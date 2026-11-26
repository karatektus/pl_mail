<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A POST with a token from an expired session is refused with a 403, not sent
 * to the login page (#745).
 *
 * The case that matters is the remember-me one, because it is the only way the
 * two meet: the session ends, the "keep me logged in" cookie signs the browser
 * back in on its next request, and the page still on screen carries a CSRF
 * token minted in the session that is gone. Symfony counts a remember-me login
 * as not fully authenticated, and its firewall answers the security
 * component's AccessDeniedException for such a user by starting a login — a
 * 302 to /login, which fetch() followed to the inbox. Measured in production
 * as hundreds of whole-mailbox renders a minute from one tab.
 *
 * A user signed in by loginUser() would not show it: their token counts as
 * fully authenticated and was always answered with a 403. So this signs in
 * through the real form with the box ticked, and comes back on the cookie.
 *
 * Since the page's `ajax` token became stateless (csrf.yaml) it no longer goes
 * stale with the session: the morning-after post from the old page is simply
 * accepted, which is the second test. A post is still refused when it comes
 * from another site, and THAT refusal, for the same remember-me user, is what
 * the first test now pins to a 403.
 */
final class StaleCsrfTokenIsRefusedNotRedirectedTest extends WebTestCase
{
    private const string PASSWORD = 'correct-horse-battery-staple';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testAStaleTokenAfterARememberMeLoginIsA403(): void
    {
        $user = $this->user();

        $this->signInRemembered($user);
        $stale = $this->pageToken();

        // The next morning: the session is gone, the remember-me cookie is
        // not. Every other cookie goes, whatever the session one is called in
        // this environment.
        $jar = $this->client->getCookieJar();
        $sessionCookies = 0;

        foreach ($jar->all() as $cookie) {
            if ('REMEMBERME' !== $cookie->getName() && 'plmail_trusted_device' !== $cookie->getName()) {
                $jar->expire($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
                ++$sessionCookies;
            }
        }

        self::assertGreaterThan(0, $sessionCookies, 'there was no session cookie to lose');

        $this->client->request('GET', '/mail/inbox');
        self::assertResponseIsSuccessful('remember-me did not sign the browser back in');

        // Refused for where it came from, not for its age.
        $this->client->request(
            'POST',
            '/mail/sidebar/section-collapsed',
            server: [
                'CONTENT_TYPE'        => 'application/json',
                'HTTP_ACCEPT'         => 'application/json',
                'HTTP_X_CSRF_TOKEN'   => $stale,
                'HTTP_ORIGIN'         => 'https://evil.example',
                'HTTP_SEC_FETCH_SITE' => 'cross-site',
            ],
            content: json_encode(['key' => User::SIDEBAR_SECTION_LABELS, 'collapsed' => true], JSON_THROW_ON_ERROR),
        );

        self::assertFalse(
            $this->client->getResponse()->isRedirection(),
            'a refused POST was sent to the login page, and fetch() follows that to a full inbox render',
        );
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->find($user->id);
        self::assertInstanceOf(User::class, $stored);
        self::assertSame([], $stored->collapsedSidebarSections, 'the stale token wrote the preference anyway');
    }

    /**
     * The page left open overnight: its token, from the session that is gone,
     * still works from this site — it used to be refused, and every mark-read,
     * star and archive on that page failed with "Invalid CSRF token".
     */
    public function testThePageFromTheExpiredSessionStillPostsAfterARememberMeLogin(): void
    {
        $user = $this->user();
        $this->signInRemembered($user);
        $old = $this->pageToken();

        $jar = $this->client->getCookieJar();

        foreach ($jar->all() as $cookie) {
            if ('REMEMBERME' !== $cookie->getName() && 'plmail_trusted_device' !== $cookie->getName()) {
                $jar->expire($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }

        $this->client->request(
            'POST',
            '/mail/sidebar/section-collapsed',
            server: [
                'CONTENT_TYPE'        => 'application/json',
                'HTTP_ACCEPT'         => 'application/json',
                'HTTP_X_CSRF_TOKEN'   => $old,
                'HTTP_ORIGIN'         => 'http://localhost',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ],
            content: json_encode(['key' => User::SIDEBAR_SECTION_LABELS, 'collapsed' => true], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->find($user->id);
        self::assertInstanceOf(User::class, $stored);
        self::assertSame([User::SIDEBAR_SECTION_LABELS], $stored->collapsedSidebarSections);
    }

    private function user(): User
    {
        $user = new User();
        $user->email = 'stale-csrf-'.bin2hex(random_bytes(6)).'@plmail.test';
        $user->nameFirst = 'Stale';
        $user->nameLast = 'Token';
        $user->password = static::getContainer()
            ->get(UserPasswordHasherInterface::class)
            ->hashPassword($user, self::PASSWORD);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function signInRemembered(User $user): void
    {
        $crawler = $this->client->request('GET', '/login');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->followRedirects(true);

        try {
            $this->client->request('POST', '/login', [
                'email'        => $user->email,
                'password'     => self::PASSWORD,
                '_csrf_token'  => $token,
                '_remember_me' => '1',
            ]);
        } finally {
            $this->client->followRedirects(false);
        }

        self::assertSame(
            '/mail/inbox',
            (string) parse_url($this->client->getRequest()->getUri(), PHP_URL_PATH),
            'the password login did not land in the mailbox',
        );
        self::assertNotNull($this->client->getCookieJar()->get('REMEMBERME'), 'no remember-me cookie was issued');
    }

    private function pageToken(): string
    {
        $crawler = $this->client->request('GET', '/mail/inbox');

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }
}
