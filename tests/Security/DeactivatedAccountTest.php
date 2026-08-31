<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A suspended account holds the right password and still cannot get in.
 *
 * `deactivatedAt` is a column, and a column that nothing consults is a setting
 * that quietly does nothing — which is the failure worth a test here. An
 * administrator switching somebody off and watching them carry on reading their
 * mail is worse than there being no switch, because the panel says otherwise.
 *
 * Driven through the real login form rather than against the checker, for the
 * reason the trusted-device suite gives about its own subject: what is being
 * pinned is not the checker's logic but WHO CALLS IT, and every one of the four
 * claims below lives somewhere different — the firewall's `user_checker`
 * option, the choice of checkPostAuth over checkPreAuth, and
 * UserProvider::refreshUser(), which is not a checker at all. A unit test of
 * App\Security\UserChecker would pass against a firewall that never registered
 * it.
 */
final class DeactivatedAccountTest extends WebTestCase
{
    private const string PASSWORD = 'correct-horse-battery-staple';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    /**
     * Reboot disabled so every request runs against the one container holding
     * the transaction below — a rebooted kernel opens a second connection that
     * cannot see the user this test just created.
     */
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->user = $this->seedUser();
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testTheRightPasswordDoesNotOpenASuspendedAccount(): void
    {
        $this->suspend();

        $this->signIn(self::PASSWORD);

        self::assertSame('/login', $this->currentPath(), 'a suspended account signed in');
        self::assertStringContainsString(
            'switched off by an administrator',
            (string) $this->client->getResponse()->getContent(),
            'the login form did not say why it refused',
        );
    }

    /**
     * The refusal reaches the account holder and nobody else.
     *
     * This is the claim that decides checkPostAuth over checkPreAuth, and it is
     * the one that would be silently lost by "simplifying" the checker into the
     * conventional method. Refusing before the password is verified answers
     * "is this address a suspended account here?" to anybody who types the
     * address — the question Symfony's `hide_user_not_found` exists to keep
     * unanswerable, handed back for exactly the accounts worth asking about.
     */
    public function testAWrongPasswordLearnsNothingAboutTheAccountBeingSuspended(): void
    {
        $this->suspend();

        $this->signIn('not-the-password');

        self::assertSame('/login', $this->currentPath());
        self::assertStringNotContainsString(
            'switched off by an administrator',
            (string) $this->client->getResponse()->getContent(),
            'the login form told a stranger that this address is a suspended account',
        );
    }

    /**
     * Suspending somebody who is signed in takes effect now, not whenever their
     * session would have lapsed.
     *
     * Symfony runs user checkers at authentication only — ContextListener
     * rehydrates a session token straight through the user provider and
     * consults no checker — so this half cannot be done by App\Security
     * \UserChecker and is done by UserProvider::refreshUser(). Without it,
     * switching off the account of the person actually at the keyboard changes
     * nothing they can observe, which is the case an administrator is most
     * likely to be acting on.
     */
    public function testASessionThatIsAlreadyOpenEndsOnTheNextRequest(): void
    {
        $this->signIn(self::PASSWORD);

        self::assertSame('/mail/inbox', $this->currentPath(), 'the fixture could not sign in at all');

        $this->suspend();

        $this->follow(fn () => $this->client->request('GET', '/mail/inbox'));

        self::assertSame('/login', $this->currentPath(), 'a suspended user kept the session they already had');
    }

    /** Reversible is the whole reason this exists beside removal. */
    public function testSwitchingTheAccountBackOnLetsThemSignInAgain(): void
    {
        $this->suspend();
        $this->signIn(self::PASSWORD);
        self::assertSame('/login', $this->currentPath());

        $this->reload()->deactivatedAt = null;
        $this->em->flush();
        $this->em->clear();

        $this->signIn(self::PASSWORD);

        self::assertSame('/mail/inbox', $this->currentPath(), 'a reactivated account still could not sign in');
    }

    // ── fixtures and forms ────────────────────────────────────────────────────

    private function suspend(): void
    {
        $this->reload()->deactivatedAt = new DateTimeImmutable();

        $this->em->flush();

        // Cleared so the next request re-reads the row. UserProvider::
        // refreshUser() goes through the repository, which would otherwise
        // answer from the identity map this test has just written to — and the
        // test would pass without any of it reaching the database.
        $this->em->clear();
    }

    private function reload(): User
    {
        $user = $this->em->find(User::class, $this->user->id);

        self::assertNotNull($user, 'the fixture user vanished');

        return $user;
    }

    private function signIn(string $password): void
    {
        $crawler = $this->client->request('GET', '/login');

        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->follow(fn () => $this->client->request('POST', '/login', [
            'email'       => $this->user->email,
            'password'    => $password,
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
        return (string) parse_url((string) $this->client->getRequest()->getUri(), PHP_URL_PATH);
    }

    private function seedUser(): User
    {
        $user = new User();
        $user->email = 'suspended-' . bin2hex(random_bytes(6)) . '@plmail.test';
        $user->nameFirst = 'Sus';
        $user->nameLast = 'Pended';
        $user->roles = [User::ROLE_USER];
        $user->password = static::getContainer()
            ->get(UserPasswordHasherInterface::class)
            ->hashPassword($user, self::PASSWORD);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
