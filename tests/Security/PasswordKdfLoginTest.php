<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User\User;
use App\Security\PasswordKdf;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Signing in with a password the browser stretched, and the one sign-in that
 * moves an account over to it.
 *
 * Driven through the real form and the real prelogin, posting what
 * login_controller.js posts: PasswordKdf::derive() stands in for the browser,
 * and PasswordKdfTest holds the two to the same arithmetic.
 *
 * The claims that matter are the ones about what can NOT get in. Once an
 * account has moved over, its stored hash is of an auth hash, and the password
 * typed as it is must no longer open it — otherwise the server could still be
 * handed the password, and the whole point of not seeing it would be a
 * convention the login form keeps rather than something the server holds.
 */
final class PasswordKdfLoginTest extends WebTestCase
{
    private const string PASSWORD = 'a-password-they-type';

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

        // The prelogin and login counters live in a filesystem pool that
        // outlives the transaction, and this suite signs in a lot.
        static::getContainer()->get('cache.rate_limiter')->clear();

        $this->user = $this->seedUser();
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The first sign-in after the update: the prelogin says `migrate`, the
     * form sends the password one last time beside the auth hash, and from
     * then on the stored hash is of the auth hash.
     */
    public function testTheFirstSignInMovesTheAccountOver(): void
    {
        $params = $this->prelogin();

        self::assertTrue($params['migrate'], 'an account on a hash of the password was not asked to move over');

        $this->signIn(password: self::PASSWORD, authHash: PasswordKdf::derive(self::PASSWORD, $params['salt'], $params['iterations']));

        self::assertSame('/mail/inbox', $this->currentPath(), 'the migrating sign-in did not get in');

        $user = $this->reload();

        self::assertTrue($user->hasClientDerivedPassword());
        self::assertSame($params['salt'], $user->kdfSalt, 'the salt stored is not the one the browser stretched with');
        self::assertTrue($this->hashIsOf($user, PasswordKdf::derive(self::PASSWORD, $params['salt'], $params['iterations'])));
    }

    public function testAMovedAccountSignsInWithTheAuthHashAlone(): void
    {
        $this->moveOver();

        $params = $this->prelogin();

        self::assertFalse($params['migrate']);

        $this->signIn(authHash: PasswordKdf::derive(self::PASSWORD, $params['salt'], $params['iterations']));

        self::assertSame('/mail/inbox', $this->currentPath());
    }

    /**
     * The claim the feature rests on: a moved account cannot be opened by
     * handing the server the password.
     */
    public function testAMovedAccountRefusesThePasswordItself(): void
    {
        $this->moveOver();

        $this->signIn(password: self::PASSWORD);

        self::assertSame('/login', $this->currentPath(), 'the server accepted the plain password for a moved account');
    }

    /**
     * Nor can the migrating shape be used to sneak it past: the password
     * beside an auth hash is checked against the stored hash like any other,
     * and for a moved account that hash is not of the password.
     */
    public function testAMovedAccountCannotBeMovedAgainWithThePassword(): void
    {
        $this->moveOver();
        $before = $this->reload()->password;

        $this->signIn(password: self::PASSWORD, authHash: PasswordKdf::derive(self::PASSWORD, base64_encode(random_bytes(16)), 1000));

        self::assertSame('/login', $this->currentPath());
        self::assertSame($before, $this->reload()->password, 'a refused sign-in rewrote the stored hash');
    }

    /**
     * The sign-in leaves the session's half of an unlocked keyring behind, so
     * the first page after it can open the keys without asking again.
     */
    public function testTheSignInKeepsTheUnlockKeyItWasSent(): void
    {
        $this->moveOver();

        $params    = $this->prelogin();
        $unlockKey = base64_encode(random_bytes(32));

        $this->signIn(authHash: PasswordKdf::derive(self::PASSWORD, $params['salt'], $params['iterations']), unlockKey: $unlockKey);
        $this->client->request('GET', '/keyring/unlock');

        self::assertResponseIsSuccessful();
        self::assertSame($unlockKey, json_decode((string) $this->client->getResponse()->getContent(), true)['unlockKey']);
    }

    /**
     * A browser without JavaScript, or a login page cached from before the
     * update, still gets an account in that has not moved — it just does not
     * move it.
     */
    public function testThePasswordAloneStillOpensAnAccountThatHasNotMoved(): void
    {
        $this->signIn(password: self::PASSWORD);

        self::assertSame('/mail/inbox', $this->currentPath());
        self::assertFalse($this->reload()->hasClientDerivedPassword(), 'an account moved without an auth hash to move to');
    }

    /** A wrong password beside an auth hash moves nothing. */
    public function testAWrongPasswordMovesNothing(): void
    {
        $params = $this->prelogin();

        $this->signIn(password: 'not-the-password', authHash: PasswordKdf::derive('not-the-password', $params['salt'], $params['iterations']));

        self::assertSame('/login', $this->currentPath());
        self::assertFalse($this->reload()->hasClientDerivedPassword());
    }

    // ── fixtures and forms ────────────────────────────────────────────────────

    /**
     * @return array{salt: string, iterations: int, migrate: bool}
     */
    private function prelogin(): array
    {
        $this->client->request('POST', '/login/prelogin', ['email' => $this->user->email]);

        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function moveOver(): void
    {
        $params = $this->prelogin();

        $this->signIn(password: self::PASSWORD, authHash: PasswordKdf::derive(self::PASSWORD, $params['salt'], $params['iterations']));

        self::assertTrue($this->reload()->hasClientDerivedPassword(), 'the fixture could not move the account over');

        // Signed out by dropping the session cookie: what is under test is the
        // next sign-in, not the logout form.
        $this->client->getCookieJar()->clear();
    }

    private function signIn(string $password = '', string $authHash = '', string $unlockKey = ''): void
    {
        $crawler = $this->client->request('GET', '/login');

        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $fields = ['email' => $this->user->email, '_csrf_token' => $token];

        if ('' !== $password) {
            $fields['password'] = $password;
        }

        if ('' !== $authHash) {
            $fields['auth_hash'] = $authHash;
        }

        if ('' !== $unlockKey) {
            $fields['unlock_key'] = $unlockKey;
        }

        $this->client->followRedirects(true);

        try {
            $this->client->request('POST', '/login', $fields);
        } finally {
            $this->client->followRedirects(false);
        }
    }

    private function hashIsOf(User $user, string $candidate): bool
    {
        return static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, $candidate);
    }

    private function currentPath(): string
    {
        return (string) parse_url((string) $this->client->getRequest()->getUri(), PHP_URL_PATH);
    }

    private function reload(): User
    {
        $this->em->clear();

        $user = $this->em->find(User::class, $this->user->id);

        self::assertNotNull($user, 'the fixture user vanished');

        return $user;
    }

    private function seedUser(): User
    {
        $user = new User();
        $user->email = 'stretched-' . bin2hex(random_bytes(6)) . '@plmail.test';
        $user->nameFirst = 'Stretched';
        $user->nameLast = 'Password';
        $user->roles = [User::ROLE_USER];
        $user->useServerHashedPassword(
            static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD),
        );

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
