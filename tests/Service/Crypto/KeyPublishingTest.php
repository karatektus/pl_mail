<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Domain\Enum\Crypto\KeyPublicationState;
use App\Domain\Exception\ContactKeyRefused;
use App\Entity\Crypto\AddressKey;
use App\Entity\Crypto\KeyPublication;
use App\Entity\User\User;
use App\Repository\Crypto\AddressKeyRepository;
use App\Repository\Crypto\KeyPublicationRepository;
use App\Service\Crypto\KeyFetcher;
use App\Service\Crypto\KeyPublishing;
use App\Service\Crypto\KeyServer\MailvelopeKeyServer;
use App\Service\Crypto\KeyServer\OpenPgpOrgKeyServer;
use App\Service\Crypto\KeyServerLookup;
use App\Service\Crypto\PublicKeyReader;
use App\Tests\Support\Crypto\Gpg;
use App\Tests\Support\Crypto\SeedsWkdPeople;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Publishing the person's own key to keys.openpgp.org (phase 6g of #28, #28
 * comment 6216704815): the two VKS requests in their shapes, the states the
 * server answers kept per address, refreshing that never publishes, the
 * revocation of a compromised key sent to every server the key went to — and
 * nothing at all requested when the installation or the person said no.
 *
 * Against MockHttpClient with every request recorded and an injected
 * resolver, so nothing here asks DNS or the internet; real gpg keys, and the
 * real database for the address keys and their rows.
 */
final class KeyPublishingTest extends KernelTestCase
{
    use SeedsWkdPeople;

    private const string HOST = 'keys.openpgp.org';

    private EntityManagerInterface $em;
    private User $user;
    private Gpg $gpg;
    private AddressKey $key;

    /** @var list<array{method: string, url: string, body: mixed}> */
    private array $requests = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        [$this->user, $userKey] = $this->seedWkdPerson(domain: 'publish.test');
        $this->gpg              = new Gpg((string) $this->user->email);
        $this->key              = $this->seedWkdKey($this->user, $userKey, $this->gpg, (string) $this->user->email);
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testPublishingUploadsTheKeyAndAsksTheServerToMailTheLink(): void
    {
        $rows = $this->publishing([$this->vks('unpublished'), $this->vks('pending')])->publish($this->user, (int) $this->key->id, self::HOST, 'de');

        self::assertSame([
            ['method' => 'POST', 'url' => 'https://keys.openpgp.org/vks/v1/upload', 'body' => ['keytext' => $this->gpg->armored]],
            ['method' => 'POST', 'url' => 'https://keys.openpgp.org/vks/v1/request-verify', 'body' => ['token' => 'the-token', 'addresses' => [$this->user->email], 'locale' => ['de']]],
        ], $this->requests);
        self::assertSame([KeyPublicationState::Pending], array_map(static fn (KeyPublication $row): KeyPublicationState => $row->state, $rows));
        self::assertSame([self::HOST], array_map(static fn (KeyPublication $row): string => $row->host, $this->rows()));
    }

    /** An address the server already confirmed is not mailed again. */
    public function testAnAddressAlreadyConfirmedIsNotAskedAgain(): void
    {
        $rows = $this->publishing([$this->vks('published')])->publish($this->user, (int) $this->key->id, self::HOST, 'en');

        self::assertCount(1, $this->requests);
        self::assertSame(KeyPublicationState::Published, $rows[0]->state);
    }

    /** Refreshing is an upload of the same key: the state, and no mail. */
    public function testRefreshingAsksAgainWithoutMailingAnybody(): void
    {
        $publishing = $this->publishing([$this->vks('unpublished'), $this->vks('pending'), $this->vks('published')]);

        $publishing->publish($this->user, (int) $this->key->id, self::HOST, 'en');
        $rows = $publishing->refresh($this->user, (int) $this->key->id, self::HOST);

        self::assertSame('https://keys.openpgp.org/vks/v1/upload', $this->requests[2]['url']);
        self::assertCount(3, $this->requests);
        self::assertSame(KeyPublicationState::Published, $rows[0]->state);
        self::assertCount(1, $this->rows(), 'one row per address, updated rather than added');
    }

    /** Refreshing a key that was never published must not publish it. */
    public function testRefreshingNeverPublishes(): void
    {
        $this->expectRefusal('encryption.publish.not_published');

        try {
            $this->publishing([$this->vks('unpublished')])->refresh($this->user, (int) $this->key->id, self::HOST);
        } finally {
            self::assertSame([], $this->requests);
        }
    }

    /**
     * The installation's switch: a host APP_KEY_SERVERS does not list — or
     * an empty list — is never offered and never asked. Mailvelope is listed
     * but has no upload API here, so it is not offered either.
     */
    public function testNothingIsSentToAServerTheInstallationDoesNotList(): void
    {
        foreach (['', 'keys.mailvelope.com'] as $configured) {
            $publishing = $this->publishing([$this->vks('pending')], $configured);

            self::assertSame([], $publishing->hostsFor($this->user));

            try {
                $publishing->publish($this->user, (int) $this->key->id, self::HOST, 'en');
                self::fail('published to a server the installation does not list');
            } catch (ContactKeyRefused $refused) {
                self::assertSame('encryption.publish.unavailable', $refused->translationKey);
            }
        }

        self::assertSame([], $this->requests);
    }

    /** The person's switch: off, nothing is offered and nothing is sent. */
    public function testNothingIsSentWhenThePersonSwitchedKeyServersOff(): void
    {
        $this->user->lookUpKeyServers = false;
        $publishing                   = $this->publishing([$this->vks('pending')]);

        self::assertSame([], $publishing->hostsFor($this->user));
        $this->expectRefusal('encryption.publish.unavailable');

        try {
            $publishing->publish($this->user, (int) $this->key->id, self::HOST, 'en');
        } finally {
            self::assertSame([], $this->requests);
        }
    }

    public function testOnBothSwitchesTheServerIsOffered(): void
    {
        self::assertSame([self::HOST], $this->publishing([])->hostsFor($this->user));
    }

    public function testAKeyNoLongerInUseIsNotPublished(): void
    {
        $this->key->markObsolete();
        $this->expectRefusal('encryption.publish.not_active');

        try {
            $this->publishing([$this->vks('pending')])->publish($this->user, (int) $this->key->id, self::HOST, 'en');
        } finally {
            self::assertSame([], $this->requests);
        }
    }

    public function testAnotherPersonsKeyIsNotPublished(): void
    {
        [$other] = $this->seedWkdPerson(domain: 'publish.test');

        $this->expectRefusal('encryption.publish.not_found');
        $this->publishing([$this->vks('pending')])->publish($other, (int) $this->key->id, self::HOST, 'en');
    }

    /** A refusal or an unreachable server is said, and nothing is kept. */
    public function testAServerThatRefusesKeepsNothing(): void
    {
        foreach ([new MockResponse('{"error":"Parsing of key data failed."}', ['http_code' => 400]), new MockResponse('', ['error' => 'Connection refused'])] as $answer) {
            try {
                $this->publishing([$answer])->publish($this->user, (int) $this->key->id, self::HOST, 'en');
                self::fail('kept a refused upload');
            } catch (ContactKeyRefused $refused) {
                self::assertSame('encryption.publish.failed', $refused->translationKey);
            }
        }

        self::assertSame([], $this->rows());
    }

    /** An answer about another key is not an answer about this one. */
    public function testAnAnswerForAnotherFingerprintIsRefused(): void
    {
        $this->expectRefusal('encryption.publish.failed');
        $this->publishing([$this->vks('pending', fingerprint: str_repeat('ab', 20))])->publish($this->user, (int) $this->key->id, self::HOST, 'en');
    }

    /** Publishing spends from the budget the lookups spend from. */
    public function testPublishingCountsAgainstTheLookupBudget(): void
    {
        $publishing = $this->publishing([$this->vks('published'), $this->vks('published')], limit: 1);

        $publishing->publish($this->user, (int) $this->key->id, self::HOST, 'en');

        $this->expectRefusal('contact_keys.lookup.rate_limited');
        $publishing->publish($this->user, (int) $this->key->id, self::HOST, 'en');
    }

    /**
     * A compromised key that was published: its revocation — the public key
     * with the revocation signature, which the browser made — goes to the
     * server, so the server hands out the revoked key. No private key.
     */
    public function testACompromisedKeysRevocationIsUploadedWhereTheKeyWasPublished(): void
    {
        $publishing = $this->publishing([$this->vks('pending'), $this->vks('pending'), $this->vks('revoked')]);
        $revoked    = $this->gpg->revoked();

        $publishing->publish($this->user, (int) $this->key->id, self::HOST, 'en');
        $this->key->markCompromised();
        $publishing->revoke($this->user, (int) $this->key->id, $revoked);

        self::assertSame('https://keys.openpgp.org/vks/v1/upload', $this->requests[2]['url']);
        self::assertStringContainsString('BEGIN PGP PUBLIC KEY BLOCK', $this->requests[2]['body']['keytext']);
        self::assertStringNotContainsString('PRIVATE', $this->requests[2]['body']['keytext']);
        self::assertTrue(new PublicKeyReader()->read($this->requests[2]['body']['keytext'])[0]->revoked);
        self::assertSame([KeyPublicationState::Revoked], array_map(static fn (KeyPublication $row): KeyPublicationState => $row->state, $this->rows()));
        self::assertNotNull($this->key->revokedPublicKey);
    }

    /** Never published, nothing to tell anybody: the revocation is only kept. */
    public function testARevocationGoesNowhereTheKeyWasNotPublished(): void
    {
        $this->key->markCompromised();
        $this->publishing([$this->vks('revoked')])->revoke($this->user, (int) $this->key->id, $this->gpg->revoked());

        self::assertSame([], $this->requests);
        self::assertNotNull($this->key->revokedPublicKey);
    }

    /**
     * A revocation that did not get through — the server down at that moment
     * — is sent on the next refresh, from the copy kept on the key.
     */
    public function testARevocationThatDidNotGetThroughIsSentOnRefresh(): void
    {
        $publishing = $this->publishing([$this->vks('published'), new MockResponse('', ['http_code' => 503]), $this->vks('revoked')]);

        $publishing->publish($this->user, (int) $this->key->id, self::HOST, 'en');
        $this->key->markCompromised();
        $publishing->revoke($this->user, (int) $this->key->id, $this->gpg->revoked());

        self::assertSame(KeyPublicationState::Published, $this->rows()[0]->state, 'the 503 changed nothing');

        $publishing->refresh($this->user, (int) $this->key->id, self::HOST);

        self::assertCount(3, $this->requests);
        self::assertTrue(new PublicKeyReader()->read($this->requests[2]['body']['keytext'])[0]->revoked);
        self::assertSame(KeyPublicationState::Revoked, $this->rows()[0]->state);
    }

    /**
     * The person's switch does not hold back the revocation of a key they
     * published: leaving the server to vouch for a compromised key is the
     * worse outcome. The installation's list still does.
     */
    public function testTheRevocationGoesOutWithThePersonsSwitchOffButNotPastTheInstallation(): void
    {
        $this->publishing([$this->vks('published')])->publish($this->user, (int) $this->key->id, self::HOST, 'en');
        $this->key->markCompromised();
        $this->user->lookUpKeyServers = false;

        $this->publishing([$this->vks('revoked')], '')->revoke($this->user, (int) $this->key->id, $this->gpg->revoked());
        self::assertCount(1, $this->requests, 'not past an installation that no longer lists the server');

        $this->publishing([$this->vks('revoked')])->revoke($this->user, (int) $this->key->id, $this->gpg->revoked());
        self::assertCount(2, $this->requests);
    }

    /** Only this key, revoked, is taken as its revocation. */
    public function testSomethingElseIsNotTakenAsTheRevocation(): void
    {
        $this->key->markCompromised();

        foreach ([$this->gpg->armored, new Gpg((string) $this->user->email)->revoked()] as $notIt) {
            try {
                $this->publishing([])->revoke($this->user, (int) $this->key->id, $notIt);
                self::fail('took something else for the revocation');
            } catch (ContactKeyRefused $refused) {
                self::assertSame('encryption.publish.not_a_revocation', $refused->translationKey);
            }
        }

        self::assertNull($this->key->revokedPublicKey);
    }

    /** The view the keyring hands the page: per key, per address. */
    public function testTheViewListsTheRowsByKey(): void
    {
        $publishing = $this->publishing([$this->vks('unpublished'), $this->vks('pending')]);

        $publishing->publish($this->user, (int) $this->key->id, self::HOST, 'en');

        $view = $publishing->view($this->user);

        self::assertSame([(int) $this->key->id], array_keys($view));
        self::assertSame(['host' => self::HOST, 'email' => $this->user->email, 'state' => 'pending'], array_diff_key($view[(int) $this->key->id][0], ['checkedAt' => true]));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function entityManager(): EntityManagerInterface
    {
        return $this->em;
    }

    /** What keys.openpgp.org answers about this key. */
    private function vks(string $state, ?string $fingerprint = null): MockResponse
    {
        return new MockResponse((string) json_encode([
            'key_fpr' => strtoupper($fingerprint ?? $this->gpg->fingerprint),
            'status'  => [(string) $this->user->email => $state],
            'token'   => 'the-token',
        ]), ['response_headers' => ['Content-Type' => 'application/json']]);
    }

    /**
     * @param list<MockResponse> $responses one per request, in order
     */
    private function publishing(array $responses, string $configured = 'keys.openpgp.org,keys.mailvelope.com', int $limit = 30): KeyPublishing
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => json_decode((string) ($options['body'] ?? ''), true)];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        $fetcher = new KeyFetcher(
            $client,
            new ArrayAdapter(),
            new RateLimiterFactory(['id' => 'publish', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 hour'], new InMemoryStorage()),
            new NullLogger(),
            Closure::fromCallable(static fn (string $host): array => ['93.184.215.14']),
        );

        $container = self::getContainer();

        return new KeyPublishing(
            $fetcher,
            new PublicKeyReader(),
            new KeyServerLookup($fetcher, new PublicKeyReader(), [new OpenPgpOrgKeyServer(), new MailvelopeKeyServer()], $configured, new NullLogger()),
            $container->get(AddressKeyRepository::class),
            $container->get(KeyPublicationRepository::class),
            $this->em,
            $container->get(ClockInterface::class),
            new NullLogger(),
            [new OpenPgpOrgKeyServer()],
        );
    }

    /**
     * @return list<KeyPublication>
     */
    private function rows(): array
    {
        return $this->em->getRepository(KeyPublication::class)->findBy(['addressKey' => $this->key]);
    }

    private function expectRefusal(string $translationKey): void
    {
        $this->expectException(ContactKeyRefused::class);
        $this->expectExceptionMessage($translationKey);
    }
}
