<?php

declare(strict_types=1);

namespace App\Tests\Controller\Crypto;

use App\Domain\Enum\Crypto\AddressKeyStatus;
use App\Domain\Enum\Crypto\UserKeyStatus;
use App\Entity\Crypto\AddressKey;
use App\Entity\Crypto\UserKey;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Security\PasswordKdf;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The keyring holds the rules a browser cannot be trusted to keep on its own.
 *
 * The browser does all the cryptography, so none of it is exercised here —
 * the blobs are well-formed nonsense. What is pinned is what the server
 * refuses and what it keeps: a key for somebody else's address, a second
 * keyring over the first, a password change that would strand a key, and the
 * quiet deletion of a key that has been handed out. Each of those, let
 * through once, is a keyring nobody can open again or a key that never
 * should have been anybody's.
 */
final class KeyringControllerTest extends WebTestCase
{
    private const string PASSWORD = 'the-password-the-keys-hang-off';
    private const string ADDRESS  = 'keyring-owner@example.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        static::getContainer()->get('cache.rate_limiter')->clear();

        $this->user = $this->seedUser();
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testTheStateNamesTheOwnedAddressesAndNoKeysYet(): void
    {
        $state = $this->state();

        self::assertTrue($state['enabled']);
        self::assertSame([self::ADDRESS], $state['addresses']);
        self::assertSame([], $state['userKeys']);
        self::assertSame($this->user->kdfSalt, $state['kdf']['salt']);
    }

    public function testAKeyringIsStoredAsTheBrowserMadeIt(): void
    {
        $state = $this->post('/keyring', $this->keyring());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $state['userKeys']);
        self::assertCount(1, $state['addressKeys']);
        self::assertSame([['email' => self::ADDRESS, 'primary' => true]], $state['addressKeys'][0]['addresses']);
        self::assertSame('device', $state['copies'][0]['kind']);
    }

    /**
     * Two browsers signing in at once would each make one, and the second
     * would orphan every key of the first under a user key nobody remembers.
     */
    public function testASecondKeyringIsRefusedWhileTheFirstIsActive(): void
    {
        $this->post('/keyring', $this->keyring());
        $this->post('/keyring', $this->keyring('b'));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->em->getRepository(UserKey::class)->findBy(['usr' => $this->user]));
    }

    /** A key bound to somebody else's address would be offered under it. */
    public function testAKeyForAnAddressThePersonDoesNotOwnIsRefusedAndNothingIsStored(): void
    {
        $keyring = $this->keyring();
        $keyring['addressKeys'][0]['addresses'] = [['email' => 'somebody-else@example.test', 'primary' => true]];

        $this->post('/keyring', $keyring);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->em->getRepository(UserKey::class)->findBy(['usr' => $this->user]));
    }

    public function testAWriteWithoutTheKeyringTokenIsRefused(): void
    {
        $this->client->request('POST', '/keyring', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($this->keyring(), JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The key plMail made a minute ago, never handed to anybody, is replaced
     * by the imported one — two keys for one address, one of which was never
     * anybody's, would only confuse whoever looks.
     */
    public function testImportingDeletesAGeneratedKeyNobodyHasSeen(): void
    {
        $state = $this->post('/keyring', $this->keyring());

        $this->post('/keyring/address-keys', [
            'userKeyId'   => $state['userKeys'][0]['id'],
            'addressKeys' => [$this->addressKey('c', 'imported')],
        ]);

        $fingerprints = array_map(static fn (AddressKey $key): string => $key->fingerprint, $this->addressKeys());

        self::assertSame([str_repeat('c', 40)], $fingerprints);
    }

    /** Handed out once, a key is kept — something may have been sent to it. */
    public function testAKeyThatWasHandedOutIsKeptAsObsoleteRatherThanDeleted(): void
    {
        $state = $this->post('/keyring', $this->keyring());

        $this->post('/keyring/address-keys/used', ['ids' => [$state['addressKeys'][0]['id']]]);
        $this->post('/keyring/address-keys', [
            'userKeyId'   => $state['userKeys'][0]['id'],
            'addressKeys' => [$this->addressKey('c', 'imported')],
        ]);

        $statuses = [];

        foreach ($this->addressKeys() as $key) {
            $statuses[$key->fingerprint] = $key->status;
        }

        self::assertSame(AddressKeyStatus::Obsolete, $statuses[str_repeat('a', 40)] ?? null);
        self::assertSame(AddressKeyStatus::Active, $statuses[str_repeat('c', 40)] ?? null);
    }

    /**
     * A key under a locked user key is the very thing a recovery phrase is
     * kept to bring back, and the browser that replaced it could not even open
     * it to know whether it mattered.
     */
    public function testAKeyUnderALockedUserKeyIsNeverDeleted(): void
    {
        $this->post('/keyring', $this->keyring());

        $this->lockAll();

        $this->post('/keyring', $this->keyring('b'));

        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->addressKeys(), 'the old address key went with its lock');
    }

    public function testAPhraseReplacesTheOldPhraseAndDevicesAddUp(): void
    {
        $state     = $this->post('/keyring', $this->keyring());
        $userKeyId = $state['userKeys'][0]['id'];

        $this->post('/keyring/copies', ['userKeyId' => $userKeyId, 'kind' => 'phrase', 'wrapped' => $this->wrapped()]);
        $this->post('/keyring/copies', ['userKeyId' => $userKeyId, 'kind' => 'phrase', 'wrapped' => $this->wrapped()]);
        $state = $this->post('/keyring/copies', ['userKeyId' => $userKeyId, 'kind' => 'device', 'wrapped' => $this->wrapped(), 'label' => 'Firefox · Linux']);

        $kinds = array_count_values(array_column($state['copies'], 'kind'));

        self::assertSame(['device' => 2, 'phrase' => 1], $kinds);
    }

    // ── Unlocking ───────────────────────────────────────────────────────────

    public function testASessionWithoutAnUnlockKeyIsTold(): void
    {
        $this->client->request('GET', '/keyring/unlock');

        self::assertResponseStatusCodeSame(404);
    }

    public function testThePasswordOpensTheSessionAndAWrongOneDoesNot(): void
    {
        $unlockKey = base64_encode(random_bytes(32));

        $this->postRaw('/keyring/unlock', ['authHash' => $this->authHash('not-the-password'), 'unlockKey' => $unlockKey]);
        self::assertResponseStatusCodeSame(403);

        $this->postRaw('/keyring/unlock', ['authHash' => $this->authHash(self::PASSWORD), 'unlockKey' => $unlockKey]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/keyring/unlock');
        self::assertSame($unlockKey, json_decode((string) $this->client->getResponse()->getContent(), true)['unlockKey']);
    }

    // ── Passwords ───────────────────────────────────────────────────────────

    /**
     * A password change that left a key behind would leave it wrapped under a
     * password nobody has any more. So the change is refused, the password
     * stays, and the key with it.
     */
    public function testAPasswordChangeWithoutTheRewrappedKeysChangesNothing(): void
    {
        $this->post('/keyring', $this->keyring());

        $before = $this->user->password;

        $this->changePassword(rewrapped: []);

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $this->user->id);

        self::assertSame($before, $reloaded?->password, 'the password changed and stranded a key');
    }

    public function testAPasswordChangeCarriesTheRewrappedKeys(): void
    {
        $state     = $this->post('/keyring', $this->keyring());
        $userKeyId = $state['userKeys'][0]['id'];
        $rewrapped = $this->wrapped();

        $this->changePassword(rewrapped: [$userKeyId => $rewrapped]);

        $this->em->clear();
        $userKey = $this->em->find(UserKey::class, $userKeyId);

        self::assertNotNull($userKey);
        self::assertSame($rewrapped, $userKey->wrapped);
        self::assertSame(UserKeyStatus::Active, $userKey->status);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $this->client->request('GET', '/keyring');

        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $this->postRaw($path, $body);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * @param array<mixed> $body
     */
    private function postRaw(string $path, array $body): void
    {
        $this->client->request('POST', $path, server: [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->csrf(),
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function csrf(): string
    {
        $crawler = $this->client->request('GET', '/settings?section=encryption');

        return (string) $crawler->filter('[data-crypto--keyring-csrf-value]')->attr('data-crypto--keyring-csrf-value');
    }

    /**
     * Submits the real change-password form, the way PasswordChangeTest does,
     * with the keys the browser would have re-wrapped.
     *
     * @param array<int, string> $rewrapped
     */
    private function changePassword(array $rewrapped): void
    {
        $crawler    = $this->client->request('GET', '/settings?section=security');
        $form       = $crawler->filter('#settings-password form')->form();
        $iterations = (int) $form->get('change_password[new][iterations]')->getValue();
        $salt       = base64_encode(random_bytes(PasswordKdf::SALT_BYTES));

        $this->client->request('POST', (string) $form->getUri(), ['change_password' => [
            '_token'        => $form->get('change_password[_token]')->getValue(),
            'currentProof'  => $this->authHash(self::PASSWORD),
            'new'           => [
                'authHash'   => PasswordKdf::derive('a-brand-new-password', $salt, $iterations),
                'salt'       => $salt,
                'iterations' => (string) $iterations,
            ],
            'rewrappedKeys' => json_encode($rewrapped, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
        ]], server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);
    }

    private function lockAll(): void
    {
        foreach ($this->em->getRepository(UserKey::class)->findBy(['usr' => $this->user]) as $userKey) {
            $userKey->lock(new \DateTimeImmutable());
        }

        $this->em->flush();
    }

    /** @return list<AddressKey> */
    private function addressKeys(): array
    {
        $this->em->clear();

        return $this->em->getRepository(AddressKey::class)->findBy(['usr' => $this->user->id]);
    }

    /** @return array<string, mixed> */
    private function keyring(string $seed = 'a'): array
    {
        return [
            'userKey'     => $this->wrapped(),
            'addressKeys' => [$this->addressKey($seed, 'generated')],
            'deviceCopy'  => $this->wrapped(),
            'deviceLabel' => 'Chrome · Linux',
        ];
    }

    /** @return array<string, mixed> */
    private function addressKey(string $seed, string $origin): array
    {
        return [
            'fingerprint'           => str_repeat($seed, 40),
            'publicKey'             => "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\n{$seed}\n-----END PGP PUBLIC KEY BLOCK-----",
            'privateKey'            => "-----BEGIN PGP PRIVATE KEY BLOCK-----\n\n{$seed}\n-----END PGP PRIVATE KEY BLOCK-----",
            'token'                 => $this->wrapped(),
            'origin'                => $origin,
            'revocationCertificate' => null,
            'addresses'             => [['email' => self::ADDRESS, 'primary' => true]],
        ];
    }

    /** Well-formed, and opening nothing — the server never tries. */
    private function wrapped(): string
    {
        return 'v1.' . base64_encode(random_bytes(12)) . '.' . base64_encode(random_bytes(48));
    }

    private function authHash(string $password): string
    {
        return PasswordKdf::derive($password, (string) $this->user->kdfSalt, (int) $this->user->kdfIterations);
    }

    private function seedUser(): User
    {
        $hasher     = static::getContainer()->get(UserPasswordHasherInterface::class);
        $iterations = static::getContainer()->get(PasswordKdf::class)->iterations;
        $salt       = base64_encode(random_bytes(PasswordKdf::SALT_BYTES));

        $user = new User();
        $user->email     = 'keyring-' . bin2hex(random_bytes(6)) . '@plmail.test';
        $user->nameFirst = 'Key';
        $user->nameLast  = 'Ring';
        $user->roles     = [User::ROLE_USER];
        $user->useClientDerivedPassword($hasher->hashPassword($user, PasswordKdf::derive(self::PASSWORD, $salt, $iterations)), $salt, $iterations);
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
