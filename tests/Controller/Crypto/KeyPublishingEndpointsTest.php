<?php

declare(strict_types=1);

namespace App\Tests\Controller\Crypto;

use App\Command\Test\SeedKeyServerCommand;
use App\Entity\Crypto\AddressKey;
use App\Entity\User\User;
use App\Tests\Support\Crypto\Gpg;
use App\Tests\Support\Crypto\SeedsWkdPeople;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The keyring's publishing endpoints (KeyringController), through the real
 * container — whose keys.openpgp.org in the test environment is the
 * stand-in, KeyServerStub, speaking the VKS upload API from files, never the
 * internet: publishing answers the keyring with the key's rows in it, the
 * refusals are sentences for the person, a compromised key's revocation
 * reaches the server, and every write needs the keyring's token.
 */
final class KeyPublishingEndpointsTest extends WebTestCase
{
    use SeedsWkdPeople;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private Gpg $gpg;
    private AddressKey $key;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        [$this->user, $userKey] = $this->seedWkdPerson(domain: 'publish-endpoints.test');
        $this->gpg              = new Gpg((string) $this->user->email);
        $this->key              = $this->seedWkdKey($this->user, $userKey, $this->gpg, (string) $this->user->email);
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testPublishingAnswersTheKeyringWithTheKeysRowsInIt(): void
    {
        $keyring = $this->post(sprintf('/keyring/address-keys/%d/publish', $this->key->id), ['host' => 'keys.openpgp.org']);

        self::assertResponseIsSuccessful();
        self::assertSame(['keys.openpgp.org'], $keyring['publishTo']);
        self::assertSame('pending', $keyring['addressKeys'][0]['publications'][0]['state']);
        self::assertSame($this->user->email, $keyring['addressKeys'][0]['publications'][0]['email']);
        self::assertSame([$this->user->email => 'pending'], SeedKeyServerCommand::vksState($this->stub(), $this->gpg->fingerprint));
    }

    public function testPublishingWithoutTheKeyringTokenSendsNothing(): void
    {
        $this->client->request('POST', sprintf('/keyring/address-keys/%d/publish', $this->key->id), server: ['CONTENT_TYPE' => 'application/json'], content: '{"host":"keys.openpgp.org"}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], SeedKeyServerCommand::vksState($this->stub(), $this->gpg->fingerprint));
    }

    /** A refusal is a sentence for the person, not a code. */
    public function testARefusalIsSaidInThePersonsWords(): void
    {
        $answer = $this->post(sprintf('/keyring/address-keys/%d/publication', $this->key->id), ['host' => 'keys.openpgp.org']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('That key is not published there.', $answer['error']);

        $answer = $this->post(sprintf('/keyring/address-keys/%d/publish', $this->key->id), ['host' => 'keys.mailvelope.com']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('Publishing to that key server is not available', $answer['error']);
    }

    /**
     * Marked compromised with the revocation the browser made: the server it
     * was published to gets the revoked key and says so.
     */
    public function testACompromisedKeysRevocationReachesTheServer(): void
    {
        $this->post(sprintf('/keyring/address-keys/%d/publish', $this->key->id), ['host' => 'keys.openpgp.org']);

        $keyring = $this->post(sprintf('/keyring/address-keys/%d/compromised', $this->key->id), ['revokedKey' => $this->gpg->revoked()]);

        self::assertResponseIsSuccessful();
        self::assertSame('compromised', $keyring['addressKeys'][0]['status']);
        self::assertSame('revoked', $keyring['addressKeys'][0]['publications'][0]['state']);
        self::assertSame([$this->user->email => 'revoked'], SeedKeyServerCommand::vksState($this->stub(), $this->gpg->fingerprint));
    }

    /** Something that is not the key's revocation is not uploaded; the key is marked all the same. */
    public function testAWrongRevocationDoesNotStopTheKeyBeingMarked(): void
    {
        $this->post(sprintf('/keyring/address-keys/%d/publish', $this->key->id), ['host' => 'keys.openpgp.org']);

        $keyring = $this->post(sprintf('/keyring/address-keys/%d/compromised', $this->key->id), ['revokedKey' => $this->gpg->armored]);

        self::assertResponseIsSuccessful();
        self::assertSame('compromised', $keyring['addressKeys'][0]['status']);
        self::assertSame('pending', $keyring['addressKeys'][0]['publications'][0]['state']);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function entityManager(): EntityManagerInterface
    {
        return $this->em;
    }

    private function stub(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir') . '/var/test-key-servers';
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $crawler = $this->client->request('GET', '/settings?section=encryption');
        $csrf    = (string) $crawler->filter('[data-crypto--keyring-csrf-value]')->attr('data-crypto--keyring-csrf-value');

        $this->client->request('POST', $path, server: [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode($body, JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
