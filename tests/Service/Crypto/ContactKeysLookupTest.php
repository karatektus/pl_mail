<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyOutcome;
use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Domain\Enum\Crypto\MessageCrypto;
use App\Entity\Crypto\ContactKey;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\VerifySignatureMessage;
use App\Repository\Crypto\ContactKeyRepository;
use App\Service\Crypto\ContactKeys;
use App\Service\Crypto\KeyFetcher;
use App\Service\Crypto\KeyServer\MailvelopeKeyServer;
use App\Service\Crypto\KeyServer\OpenPgpOrgKeyServer;
use App\Service\Crypto\KeyServerLookup;
use App\Service\Crypto\PublicKeyReader;
use App\Service\Crypto\SignatureRecheck;
use App\Service\Crypto\WkdLookup;
use App\Tests\Command\MailFixtures;
use App\Tests\Support\Crypto\Gpg;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Where a looked-up key comes from, and that it changes nothing about trust.
 *
 * WKD is asked first, because the recipient's domain speaks for its own
 * addresses; the key servers only when it has nothing usable, in the
 * installation's order, the first usable answer winning. And a key from a key
 * server goes through trust on first use like any other: the first one is
 * Current and unpinned, a different one waits as Changed beside the key plMail
 * already holds, and the sender's signatures are checked again.
 *
 * ContactKeys is built here with a WkdLookup and a KeyServerLookup that share
 * one KeyFetcher over a MockHttpClient answering by host — so no request
 * leaves the test — and everything else from the real container and database.
 */
final class ContactKeysLookupTest extends KernelTestCase
{
    private const string ANNA = 'anna@lookup.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        $this->account = MailFixtures::account($this->em, MailFixtures::user($this->em, 'keyserver-order'));
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testWkdWinsWhenTheDomainHasAKey(): void
    {
        $domains = new Gpg(self::ANNA);
        $servers = new Gpg(self::ANNA);

        $lookup = $this->contactKeys(['openpgpkey.lookup.test' => $domains->binary, 'keys.openpgp.org' => $servers->armored])->lookUp($this->account->usr, self::ANNA);

        self::assertSame(ContactKeyOutcome::Added, $lookup?->outcome);
        self::assertSame(ContactKeyOrigin::Wkd, $lookup->origin);
        self::assertNull($lookup->host);
        self::assertSame($domains->fingerprint, $this->current()?->fingerprint);
        self::assertSame([], array_filter($this->requested, static fn (string $url): bool => str_contains($url, 'keys.')), 'a key server was asked after WKD answered');
    }

    public function testAKeyServerIsAskedOnlyWhenWkdHasNothingAndItsKeyIsCurrentAndUnpinned(): void
    {
        $key = new Gpg(self::ANNA);

        $lookup = $this->contactKeys(['keys.mailvelope.com' => (string) json_encode(['publicKeyArmored' => $key->armored])])->lookUp($this->account->usr, self::ANNA);

        self::assertSame(ContactKeyOutcome::Added, $lookup?->outcome);
        self::assertSame(ContactKeyOrigin::KeyServer, $lookup->origin);
        self::assertSame('keys.mailvelope.com', $lookup->host);

        $row = $this->current();
        self::assertSame($key->fingerprint, $row?->fingerprint);
        self::assertSame(ContactKeyOrigin::KeyServer, $row->origin);
        self::assertSame('keys.mailvelope.com', $row->originHost);
        self::assertFalse($row->pinned);

        // WKD's two URLs, then the servers in the configured order.
        self::assertStringStartsWith('https://openpgpkey.lookup.test/', $this->requested[0]);
        self::assertStringStartsWith('https://lookup.test/', $this->requested[1]);
        self::assertStringStartsWith('https://keys.openpgp.org/', $this->requested[2]);
        self::assertStringStartsWith('https://keys.mailvelope.com/', $this->requested[3]);
    }

    /**
     * WKD serving a key that is not usable for the address is WKD having
     * nothing: the key servers are asked as if it had answered 404.
     */
    public function testAnUnusableWkdKeyLetsTheKeyServersBeAsked(): void
    {
        $other = new Gpg('somebody-else@lookup.test');
        $key   = new Gpg(self::ANNA);

        $lookup = $this->contactKeys(['openpgpkey.lookup.test' => $other->binary, 'keys.openpgp.org' => $key->armored])->lookUp($this->account->usr, self::ANNA);

        self::assertSame(ContactKeyOrigin::KeyServer, $lookup?->origin);
        self::assertSame($key->fingerprint, $this->current()?->fingerprint);
    }

    public function testADifferentKeyFromAKeyServerWaitsAsChangedBesideTheKnownOne(): void
    {
        $known = new Gpg(self::ANNA);
        $other = new Gpg(self::ANNA);
        $info  = static::getContainer()->get(PublicKeyReader::class)->read($known->binary)[0];

        $contactKeys = $this->contactKeys(['keys.openpgp.org' => $other->armored]);
        $contactKeys->record($this->account->usr, self::ANNA, $info, ContactKeyOrigin::Manual);

        $lookup = $contactKeys->lookUp($this->account->usr, self::ANNA);

        self::assertSame(ContactKeyOutcome::Changed, $lookup?->outcome);
        self::assertSame('keys.openpgp.org', $lookup->host);
        self::assertSame($known->fingerprint, $this->current()?->fingerprint, 'the key server replaced the known key');

        $changed = $this->repository()->findOneBy(['usr' => $this->account->usr, 'fingerprint' => $other->fingerprint]);
        self::assertSame(ContactKeyStatus::Changed, $changed?->status);
        self::assertSame('keys.openpgp.org', $changed->originHost);
    }

    public function testAKeyFromAKeyServerQueuesTheSendersSignedMailForAnotherCheck(): void
    {
        $message = MailFixtures::message($this->em, $this->account);
        $message->fromAddress = self::ANNA;
        $message->crypto      = MessageCrypto::PgpSigned;
        $this->em->flush();

        $transport = static::getContainer()->get('messenger.transport.enrich');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $this->contactKeys(['keys.openpgp.org' => (new Gpg(self::ANNA))->armored])->lookUp($this->account->usr, self::ANNA);

        self::assertEquals([new VerifySignatureMessage((int) $message->id)], array_map(static fn ($envelope): mixed => $envelope->getMessage(), $transport->getSent()));
    }

    public function testNothingAnywhereIsNull(): void
    {
        self::assertNull($this->contactKeys([])->lookUp($this->account->usr, self::ANNA));
        self::assertNull($this->current());
    }

    public function testAPersonWhoSwitchedKeyServersOffGetsOnlyWhatWkdHas(): void
    {
        $this->account->usr->lookUpKeyServers = false;

        self::assertNull($this->contactKeys(['keys.openpgp.org' => (new Gpg(self::ANNA))->armored])->lookUp($this->account->usr, self::ANNA));
        self::assertCount(2, $this->requested, 'more than the two WKD URLs were asked');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $answers body by host; every other host 404s
     */
    private function contactKeys(array $answers): ContactKeys
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($answers): MockResponse {
            $this->requested[] = $url;
            $body              = $answers[(string) parse_url($url, PHP_URL_HOST)] ?? null;

            return null === $body ? new MockResponse('', ['http_code' => 404]) : new MockResponse($body);
        });

        $fetcher = new KeyFetcher(
            $client,
            new ArrayAdapter(),
            new RateLimiterFactory(['id' => 'lookup', 'policy' => 'fixed_window', 'limit' => 30, 'interval' => '1 hour'], new InMemoryStorage()),
            new NullLogger(),
            static fn (string $host): array => ['93.184.215.14'],
        );
        $container = static::getContainer();

        return new ContactKeys(
            $this->repository(),
            $container->get(PublicKeyReader::class),
            new WkdLookup($fetcher, new PublicKeyReader()),
            new KeyServerLookup($fetcher, new PublicKeyReader(), [new OpenPgpOrgKeyServer(), new MailvelopeKeyServer()], 'keys.openpgp.org,keys.mailvelope.com', new NullLogger()),
            $container->get(SignatureRecheck::class),
            $this->em,
            $container->get(ClockInterface::class),
        );
    }

    private function current(): ?ContactKey
    {
        return $this->repository()->findCurrent($this->account->usr, self::ANNA);
    }

    private function repository(): ContactKeyRepository
    {
        return static::getContainer()->get(ContactKeyRepository::class);
    }
}
