<?php

declare(strict_types=1);

namespace App\Tests\Controller\Crypto;

use App\Domain\Enum\Crypto\KeyringRoot;
use App\Domain\Enum\Crypto\UserKeyStatus;
use App\Entity\Crypto\UserKey;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Security\PasswordKdf;
use App\Service\User\PasswordChanger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * What a keyring hangs off, and what may never cross from one root to the
 * other (phase 6e of issue #28).
 *
 * An account single sign-on made has no password, so its keyring hangs off an
 * encryption passphrase the browser stretches like a password and the server
 * never sees. The claims pinned here are the ones that fail without a sound:
 * a password account quietly taking a passphrase, an account without one
 * getting a keyring under nothing, the session's half of the unlock kept
 * without a proof on a keyring whose secret the server CAN check, and — the
 * expensive one — something done to the login reaching keys that do not hang
 * off it, which would lock somebody out of their mail for having been given
 * a password.
 *
 * Against the real routes and a real database, like KeyringControllerTest.
 * The wrapped blobs are well-formed nonsense: the server never opens one.
 */
final class KeyringRootTest extends WebTestCase
{
    private const string PASSWORD = 'the-password-of-a-password-account';
    private const string ADDRESS  = 'root-owner@example.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $iterations;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->iterations = static::getContainer()->get(PasswordKdf::class)->iterations;
        $this->em->getConnection()->beginTransaction();

        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    // ── The root ────────────────────────────────────────────────────────────

    public function testEveryAccountWithAPasswordIsOnThePassword(): void
    {
        $user  = $this->signIn($this->passwordUser());
        $state = $this->state();

        self::assertSame(KeyringRoot::Password, $user->keyringRoot);
        self::assertSame('password', $state['root']);
        self::assertSame($state['kdf'], $state['rootKdf']);
        self::assertSame(KeyringRoot::Password, new User()->keyringRoot, 'the default is the password');
    }

    public function testAnAccountWithoutAPasswordIsOfferedThePassphrase(): void
    {
        $this->signIn($this->ssoUser());
        $state = $this->state();

        self::assertSame('passphrase', $state['root']);
        self::assertSame(['salt' => null, 'iterations' => $this->iterations], $state['rootKdf']);
        self::assertSame([], $state['userKeys']);
    }

    public function testSettingUpWithAPassphraseMakesItTheRoot(): void
    {
        $user = $this->signIn($this->ssoUser());
        $salt = $this->salt();

        $state = $this->post('/keyring', $this->keyring(['salt' => $salt, 'iterations' => $this->iterations]));

        self::assertResponseIsSuccessful();
        self::assertSame('passphrase', $state['root']);
        self::assertSame(['salt' => $salt, 'iterations' => $this->iterations], $state['rootKdf']);
        self::assertCount(1, $state['userKeys']);

        $reloaded = $this->reload($user);

        self::assertSame(KeyringRoot::Passphrase, $reloaded->keyringRoot);
        self::assertSame($salt, $reloaded->passphraseSalt);
        self::assertSame('', $reloaded->password, 'setting up encryption touched the login');
    }

    public function testAnAccountWithoutAPasswordGetsNoKeyringWithoutAPassphrase(): void
    {
        $user = $this->signIn($this->ssoUser());

        $this->post('/keyring', $this->keyring());

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->userKeys($user));
    }

    public function testAPasswordAccountCannotTakeAPassphrase(): void
    {
        $user = $this->signIn($this->passwordUser());

        $this->post('/keyring', $this->keyring(['salt' => $this->salt(), 'iterations' => $this->iterations]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->userKeys($user));
        self::assertSame(KeyringRoot::Password, $this->reload($user)->keyringRoot);
    }

    public function testAPassphraseStretchedMoreCheaplyThanAPasswordIsRefused(): void
    {
        $user = $this->signIn($this->ssoUser());

        $this->post('/keyring', $this->keyring(['salt' => $this->salt(), 'iterations' => $this->iterations - 1]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->userKeys($user));
        self::assertSame(KeyringRoot::Password, $this->reload($user)->keyringRoot);
    }

    // ── The session's half ──────────────────────────────────────────────────

    public function testThePassphraseRootKeepsTheSessionHalfWithoutAProof(): void
    {
        $this->signIn($this->ssoUser());
        $this->post('/keyring', $this->keyring(['salt' => $this->salt(), 'iterations' => $this->iterations]));

        $unlockKey = base64_encode(random_bytes(32));
        $this->post('/keyring/unlock/session', ['unlockKey' => $unlockKey]);

        self::assertResponseIsSuccessful();
        self::assertSame($unlockKey, $this->unlockKey());
    }

    public function testTheSessionHalfNeedsTheKeyringToken(): void
    {
        $this->signIn($this->ssoUser());
        $this->post('/keyring', $this->keyring(['salt' => $this->salt(), 'iterations' => $this->iterations]));

        $this->client->request('POST', '/keyring/unlock/session', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['unlockKey' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->unlockKey());
    }

    public function testTheSessionHalfWaitsForAKeyringToOpen(): void
    {
        $this->signIn($this->ssoUser());

        $this->post('/keyring/unlock/session', ['unlockKey' => base64_encode(random_bytes(32))]);

        self::assertResponseStatusCodeSame(409);
        self::assertNull($this->unlockKey());
    }

    /**
     * The proof-free way in is for the passphrase alone. A keyring on the
     * password proves the password, throttled, and a session that skipped
     * that would be a way around the throttle.
     */
    public function testAPasswordKeyringMustProveThePassword(): void
    {
        $this->signIn($this->passwordUser());
        $this->post('/keyring', $this->keyring());

        $this->post('/keyring/unlock/session', ['unlockKey' => base64_encode(random_bytes(32))]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->unlockKey());
    }

    public function testThePasswordUnlockStillWantsThePasswordAndIsThrottled(): void
    {
        $user      = $this->signIn($this->passwordUser());
        $unlockKey = base64_encode(random_bytes(32));
        $wrong     = PasswordKdf::derive('not-the-password', (string) $user->kdfSalt, (int) $user->kdfIterations);

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->post('/keyring/unlock', ['authHash' => $wrong, 'unlockKey' => $unlockKey]);
            self::assertResponseStatusCodeSame(403);
        }

        $this->post('/keyring/unlock', ['authHash' => PasswordKdf::derive(self::PASSWORD, (string) $user->kdfSalt, (int) $user->kdfIterations), 'unlockKey' => $unlockKey]);

        self::assertResponseStatusCodeSame(429, 'the right password after five wrong ones was let through unthrottled');
        self::assertNull($this->unlockKey());
    }

    // ── Independent of the login ────────────────────────────────────────────

    public function testChangingThePassphraseRewrapsEveryKeyAndLeavesTheLogin(): void
    {
        $user      = $this->signIn($this->ssoUser());
        $state     = $this->post('/keyring', $this->keyring(['salt' => $this->salt(), 'iterations' => $this->iterations]));
        $userKeyId = $state['userKeys'][0]['id'];
        $before    = $state['userKeys'][0]['wrapped'];
        $salt      = $this->salt();

        // Left out, the key would stay under a passphrase nobody has any more.
        $this->post('/keyring/passphrase', ['passphrase' => ['salt' => $salt, 'iterations' => $this->iterations], 'rewrapped' => []]);
        self::assertResponseStatusCodeSame(422);
        self::assertNotSame($salt, $this->reload($user)->passphraseSalt);

        $rewrapped = $this->wrapped();
        $state     = $this->post('/keyring/passphrase', ['passphrase' => ['salt' => $salt, 'iterations' => $this->iterations], 'rewrapped' => [$userKeyId => $rewrapped]]);

        self::assertResponseIsSuccessful();
        self::assertSame($rewrapped, $state['userKeys'][0]['wrapped']);
        self::assertNotSame($before, $rewrapped);
        self::assertSame(['salt' => $salt, 'iterations' => $this->iterations], $state['rootKdf']);
        self::assertSame('', $this->reload($user)->password);
    }

    public function testAPasswordKeyringHasNoPassphraseToChange(): void
    {
        $this->signIn($this->passwordUser());
        $state = $this->post('/keyring', $this->keyring());

        $this->post('/keyring/passphrase', ['passphrase' => ['salt' => $this->salt(), 'iterations' => $this->iterations], 'rewrapped' => [$state['userKeys'][0]['id'] => $this->wrapped()]]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * A passphrase account that was given a password changes it in Settings:
     * the browser sends no key, and the server neither refuses over the key it
     * did not get nor writes the password's key over the passphrase's.
     */
    public function testAPasswordChangeLeavesAPassphraseKeyringAlone(): void
    {
        $user    = $this->signIn($this->ssoUser());
        $state   = $this->post('/keyring', $this->keyring(['salt' => $this->salt(), 'iterations' => $this->iterations]));
        $wrapped = $state['userKeys'][0]['wrapped'];

        $this->givePassword($user);
        $before = $this->reload($user)->password;

        $this->changePassword($this->reload($user), ['999' => $this->wrapped()]);

        $reloaded = $this->reload($user);
        $userKey  = $this->em->find(UserKey::class, $state['userKeys'][0]['id']);

        self::assertNotSame($before, $reloaded->password, 'the password change was refused over a key it does not own');
        self::assertSame($wrapped, $userKey?->wrapped);
        self::assertSame(UserKeyStatus::Active, $userKey?->status);
        self::assertSame(KeyringRoot::Passphrase, $reloaded->keyringRoot);
    }

    /**
     * The forced change after `app:user:password` locks what hung off the
     * password somebody else chose — and nothing that did not.
     */
    public function testAForcedPasswordChangeLocksNoPassphraseKey(): void
    {
        $user  = $this->signIn($this->ssoUser());
        $state = $this->post('/keyring', $this->keyring(['salt' => $this->salt(), 'iterations' => $this->iterations]));

        $salt = $this->salt();
        static::getContainer()->get(PasswordChanger::class)->apply(
            $this->reload($user),
            PasswordKdf::derive('a-password-of-their-own', $salt, $this->iterations),
            $salt,
            $this->iterations,
            null,
        );

        $userKey = $this->em->find(UserKey::class, $state['userKeys'][0]['id']);

        self::assertSame(UserKeyStatus::Active, $userKey?->status);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function signIn(User $user): User
    {
        $this->client->loginUser($user);

        return $user;
    }

    private function reload(User $user): User
    {
        $this->em->clear();

        return $this->em->find(User::class, $user->id) ?? throw new \LogicException('The user is gone.');
    }

    /** @return list<UserKey> */
    private function userKeys(User $user): array
    {
        $this->em->clear();

        return $this->em->getRepository(UserKey::class)->findBy(['usr' => $user->id]);
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $this->client->request('GET', '/keyring');

        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function unlockKey(): ?string
    {
        $this->client->request('GET', '/keyring/unlock');

        return json_decode((string) $this->client->getResponse()->getContent(), true)['unlockKey'] ?? null;
    }

    /**
     * @param array<mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $this->client->request('POST', $path, server: [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->csrf(),
        ], content: json_encode($body, JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function csrf(): string
    {
        $crawler = $this->client->request('GET', '/settings?section=encryption');

        return (string) $crawler->filter('[data-crypto--keyring-csrf-value]')->attr('data-crypto--keyring-csrf-value');
    }

    /**
     * The real change-password form, as KeyringControllerTest submits it.
     *
     * @param array<int|string, string> $rewrapped
     */
    private function changePassword(User $user, array $rewrapped): void
    {
        $crawler = $this->client->request('GET', '/settings?section=security');
        $form    = $crawler->filter('#settings-password form')->form();
        $salt    = $this->salt();

        $this->client->request('POST', (string) $form->getUri(), ['change_password' => [
            '_token'        => $form->get('change_password[_token]')->getValue(),
            'currentProof'  => PasswordKdf::derive(self::PASSWORD, (string) $user->kdfSalt, (int) $user->kdfIterations),
            'new'           => [
                'authHash'   => PasswordKdf::derive('a-brand-new-password', $salt, $this->iterations),
                'salt'       => $salt,
                'iterations' => (string) $this->iterations,
            ],
            'rewrappedKeys' => json_encode($rewrapped, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
        ]], server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);
    }

    /** @param array{salt: string, iterations: int}|null $passphrase */
    private function keyring(?array $passphrase = null): array
    {
        return [
            'userKey'     => $this->wrapped(),
            'addressKeys' => [[
                'fingerprint'           => str_repeat('c', 40),
                'publicKey'             => "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nc\n-----END PGP PUBLIC KEY BLOCK-----",
                'privateKey'            => "-----BEGIN PGP PRIVATE KEY BLOCK-----\n\nc\n-----END PGP PRIVATE KEY BLOCK-----",
                'token'                 => $this->wrapped(),
                'origin'                => 'generated',
                'revocationCertificate' => null,
                'addresses'             => [['email' => self::ADDRESS, 'primary' => true]],
            ]],
            'deviceCopy'  => null,
            'deviceLabel' => null,
        ] + (null === $passphrase ? [] : ['passphrase' => $passphrase]);
    }

    private function wrapped(): string
    {
        return 'v1.' . base64_encode(random_bytes(12)) . '.' . base64_encode(random_bytes(48));
    }

    private function salt(): string
    {
        return base64_encode(random_bytes(PasswordKdf::SALT_BYTES));
    }

    private function givePassword(User $user): void
    {
        $user = $this->reload($user);
        $salt = $this->salt();

        $user->useClientDerivedPassword(
            static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, PasswordKdf::derive(self::PASSWORD, $salt, $this->iterations)),
            $salt,
            $this->iterations,
        );
        $this->em->flush();
        $this->client->loginUser($user);
    }

    private function passwordUser(): User
    {
        $user = $this->user();
        $salt = $this->salt();

        $user->useClientDerivedPassword(
            static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, PasswordKdf::derive(self::PASSWORD, $salt, $this->iterations)),
            $salt,
            $this->iterations,
        );
        $this->em->flush();

        return $user;
    }

    /** An account as OidcUserResolver creates one: the empty hash. */
    private function ssoUser(): User
    {
        $user = $this->user();
        $user->password    = '';
        $user->oidcIssuer  = 'https://sso.example.test';
        $user->oidcSubject = (string) $user->email;
        $this->em->flush();

        return $user;
    }

    private function user(): User
    {
        $user = new User();
        $user->email     = 'root-' . bin2hex(random_bytes(6)) . '@plmail.test';
        $user->nameFirst = 'Root';
        $user->nameLast  = 'Test';
        $user->roles     = [User::ROLE_USER];
        $user->password  = '';
        $this->em->persist($user);

        $account           = new Account();
        $account->usr      = $user;
        $account->email    = self::ADDRESS;
        $account->username = self::ADDRESS;
        $account->authType = 'password';
        $account->isActive = true;
        $account->imapHost = 'imap.example.test';
        $this->em->persist($account);

        $this->em->flush();

        return $user;
    }
}
