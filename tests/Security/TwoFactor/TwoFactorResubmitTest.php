<?php

declare(strict_types=1);

namespace App\Tests\Security\TwoFactor;

use App\Entity\User\User;
use App\Service\User\TwoFactor\TwoFactorEnrolment;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sending the code form more than once.
 *
 * The reported symptom was a 403 debug page — AccessDeniedHttpException, "User
 * is not in a two-factor authentication process" — on POST /2fa_check with a
 * code that was in fact valid, immediately after a config backup was restored.
 * The restore turned out to be scenery: it is what put the owner back in front
 * of a login form on a slow dev stack. The 403 is what /2fa_check has always
 * answered a browser that submits the form twice, and the first of the two
 * submits succeeds — so the person is signed in and looking at an error page
 * about not being signed in.
 *
 * Driven through the real forms, in the style of
 * {@see TrustedDeviceLifecycleTest}: the bug is in what the firewall does with
 * a second request, which nothing narrower than two requests can see.
 */
final class TwoFactorResubmitTest extends WebTestCase
{
    private const string PASSWORD = 'correct-horse-battery-staple';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    /**
     * Reboot is disabled so every request runs against the one container whose
     * connection holds the transaction below.
     */
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->user = $this->enrolledUser();
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The claim, and the bug: the second POST is answered with the inbox, not
     * with a 403 about a process that finished a moment ago.
     */
    public function testSubmittingTheCodeTwiceLandsOnTheInboxRatherThanA403(): void
    {
        $this->signInWithPassword();
        self::assertSame('/2fa', $this->currentPath(), 'expected the code prompt');

        $code = TOTP::create($this->user->totpSecret, 30, 'sha1', 6)->now();

        $this->follow(fn () => $this->client->request('POST', '/2fa_check', ['_auth_code' => $code]));
        self::assertSame('/mail/inbox', $this->currentPath(), 'the first submit should complete the login');

        // The same form, sent again: a double click, a second Enter, or the
        // browser's own resend. Before the fix this raised
        // AccessDeniedException("User is not in a two-factor authentication
        // process.") out of scheb's authenticator, which the firewall turned
        // into a bare 403 because a signed-in caller has no entry point to be
        // sent to.
        $this->follow(fn () => $this->client->request('POST', '/2fa_check', ['_auth_code' => $code]));

        self::assertSame(
            200,
            $this->client->getResponse()->getStatusCode(),
            'a second submit of the code form was refused',
        );
        self::assertSame('/mail/inbox', $this->currentPath());
    }

    /**
     * The other way back to the same refusal, and the one a user reaches
     * without meaning to: signed in, then Back. scheb's FormController throws
     * the same AccessDeniedException from the GET side.
     */
    public function testGoingBackToTheCodeFormAfterSigningInLandsOnTheInbox(): void
    {
        $this->signInThroughTwoFactor();

        $this->follow(fn () => $this->client->request('GET', '/2fa'));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('/mail/inbox', $this->currentPath());
    }

    /**
     * The softening must not reach anyone else. A caller holding no session at
     * all posts a code and is still refused — the redirect above is for a
     * browser that already cleared both factors, and nothing wider.
     */
    public function testAStrangerPostingACodeIsStillRefused(): void
    {
        $this->client->getCookieJar()->clear();

        $this->client->request('POST', '/2fa_check', ['_auth_code' => '123456']);

        self::assertNotSame(
            '/mail/inbox',
            $this->currentPath(),
            'an unauthenticated POST to the check path was sent to the inbox',
        );
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [401, 403, 302],
            'an unauthenticated POST to the check path should not be honoured',
        );
    }

    /**
     * And a login genuinely in progress still gets scheb's own handling: a
     * wrong code is a wrong code, back to the form, not a trip to the inbox.
     */
    public function testAWrongCodeStillReturnsToTheForm(): void
    {
        $this->signInWithPassword();
        self::assertSame('/2fa', $this->currentPath());

        $this->follow(fn () => $this->client->request('POST', '/2fa_check', ['_auth_code' => '000000']));

        self::assertSame('/2fa', $this->currentPath(), 'a wrong code should come back to the code form');
    }

    // ── driving the forms ─────────────────────────────────────────────────────

    private function signInThroughTwoFactor(): void
    {
        $this->signInWithPassword();

        self::assertSame('/2fa', $this->currentPath(), 'expected the code prompt');

        $this->follow(fn () => $this->client->request('POST', '/2fa_check', [
            '_auth_code' => TOTP::create($this->user->totpSecret, 30, 'sha1', 6)->now(),
        ]));

        self::assertSame('/mail/inbox', $this->currentPath());
    }

    private function signInWithPassword(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->follow(fn () => $this->client->request('POST', '/login', [
            'email'       => $this->user->email,
            'password'    => self::PASSWORD,
            '_csrf_token' => $token,
        ]));
    }

    private function follow(callable $request): void
    {
        $this->client->followRedirects(true);

        try {
            $request();
        } finally {
            $this->client->followRedirects(false);
        }
    }

    private function currentPath(): string
    {
        return (string) parse_url($this->client->getRequest()->getUri(), PHP_URL_PATH);
    }

    private function enrolledUser(): User
    {
        $user            = new User();
        $user->email     = 'resubmit-'.bin2hex(random_bytes(6)).'@plmail.test';
        $user->nameFirst = 'Two';
        $user->nameLast  = 'Factor';
        $user->password  = static::getContainer()
            ->get(UserPasswordHasherInterface::class)
            ->hashPassword($user, self::PASSWORD);

        $this->em->persist($user);
        $this->em->flush();

        $enrolment = static::getContainer()->get(TwoFactorEnrolment::class);
        $enrolment->begin($user);
        $enrolment->confirm($user, TOTP::create($user->totpSecret, 30, 'sha1', 6)->now());

        return $user;
    }
}
