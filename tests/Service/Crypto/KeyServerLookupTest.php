<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Domain\Exception\ContactKeyRefused;
use App\Entity\User\User;
use App\Service\Crypto\KeyFetcher;
use App\Service\Crypto\KeyServer\MailvelopeKeyServer;
use App\Service\Crypto\KeyServer\OpenPgpOrgKeyServer;
use App\Service\Crypto\KeyServerLookup;
use App\Service\Crypto\PublicKeyReader;
use App\Service\Crypto\WkdLookup;
use App\Tests\Support\Crypto\Gpg;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The key servers are a second way to make the server request things on a
 * person's behalf, so they are tested as WkdLookupTest tests WKD: as what
 * they could become.
 *
 * The URL is never the person's — one fixed shape per server, the address
 * URL-encoded into it — and the request goes through the same KeyFetcher
 * rules: HTTPS, no redirects, no private addresses, a size limit, a timeout,
 * a cache, and one rate limit shared with WKD. Only a key with a user ID for
 * the very address asked about counts, which is also what makes a
 * keys.openpgp.org key whose owner never confirmed the address worthless here.
 * And when the installation or the person says no, nothing is requested at
 * all.
 *
 * Against MockHttpClient and an injected resolver: no test asks real DNS or
 * the internet. The keys are real, from gpg, because "has a user ID for the
 * address" is gpg's verdict.
 */
final class KeyServerLookupTest extends TestCase
{
    private const string ADDRESS = 'anna+keys@example.org';

    /** @var list<string> */
    private array $requested = [];

    /** @var AbstractLogger&object{lines: list<string>} what KeyServerLookup logged */
    private AbstractLogger $log;

    protected function setUp(): void
    {
        $this->log = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = strtr((string) $message, ['{host}' => (string) ($context['host'] ?? '')]);
            }
        };
    }

    public function testKeysOpenPgpOrgIsAskedByEmailWithTheAddressUrlEncoded(): void
    {
        $key    = new Gpg(self::ADDRESS);
        $answer = $this->lookup([new MockResponse($key->armored)], 'keys.openpgp.org')->find($this->user(), self::ADDRESS);

        self::assertSame(['https://keys.openpgp.org/vks/v1/by-email/anna%2Bkeys%40example.org'], $this->requested);
        self::assertSame('keys.openpgp.org', $answer?->host);
        self::assertSame([$key->fingerprint], array_map(static fn ($info): string => $info->fingerprint, $answer->keys));
    }

    public function testMailvelopeIsAskedByEmailAndItsJsonReadForTheArmoredKey(): void
    {
        $key    = new Gpg(self::ADDRESS);
        $body   = (string) json_encode(['keyId' => 'abc', 'publicKeyArmored' => $key->armored, 'userIds' => []]);
        $answer = $this->lookup([new MockResponse($body)], 'keys.mailvelope.com')->find($this->user(), self::ADDRESS);

        self::assertSame(['https://keys.mailvelope.com/api/v1/key?email=anna%2Bkeys%40example.org'], $this->requested);
        self::assertSame('keys.mailvelope.com', $answer?->host);
        self::assertSame($key->fingerprint, $answer->keys[0]->fingerprint);
    }

    public function testAMailvelopeAnswerWithoutTheFieldIsNothing(): void
    {
        $key = new Gpg(self::ADDRESS);

        self::assertNull($this->lookup([new MockResponse((string) json_encode(['armored' => $key->armored]))], 'keys.mailvelope.com')->find($this->user(), self::ADDRESS));
    }

    /**
     * keys.openpgp.org hands out a key whose owner never confirmed the
     * address with every user ID stripped. That key says nothing about the
     * address, so it is not the address's key — and the next server is asked.
     * (gpg does not even take a key without a user ID in, so the reader
     * already comes back empty; usableFor() would refuse it if it did not.)
     */
    public function testAKeyWithoutAUserIdForTheAddressIsRefusedAndTheNextServerAsked(): void
    {
        $key = new Gpg(self::ADDRESS);

        $answer = $this->lookup([new MockResponse($key->withoutUserIds()), new MockResponse('', ['http_code' => 404])])->find($this->user(), self::ADDRESS);

        self::assertNull($answer);
        self::assertCount(2, $this->requested, 'the second server was not asked');
    }

    public function testAKeyForAnotherAddressIsRefused(): void
    {
        $other = new Gpg('somebody@example.org');

        self::assertNull($this->lookup([new MockResponse($other->armored)], 'keys.openpgp.org')->find($this->user(), self::ADDRESS));
    }

    public function testARevokedKeyIsRefused(): void
    {
        $key = new Gpg(self::ADDRESS);

        self::assertNull($this->lookup([new MockResponse($key->revoked())], 'keys.openpgp.org')->find($this->user(), self::ADDRESS));
    }

    public function testAnExpiredKeyIsRefused(): void
    {
        $key = new Gpg(self::ADDRESS, expired: true);

        self::assertNull($this->lookup([new MockResponse($key->armored)], 'keys.openpgp.org')->find($this->user(), self::ADDRESS));
    }

    /**
     * @return iterable<string, array{Closure(): MockResponse}>
     */
    public static function answersThatAreNotAKey(): iterable
    {
        yield 'not found'   => [static fn (): MockResponse => new MockResponse('', ['http_code' => 404])];
        yield 'too large'   => [static fn (): MockResponse => new MockResponse(str_repeat('A', KeyFetcher::MAX_BYTES + 1))];
        yield 'a redirect'  => [static fn (): MockResponse => new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://93.184.215.15/key']])];
        // MockResponse reads an empty chunk as the connection going quiet:
        // the idle timeout fires, as it would against a server that hangs.
        yield 'a timeout'   => [static fn (): MockResponse => new MockResponse((static function (): iterable { yield ''; })())];
        yield 'server error' => [static fn (): MockResponse => new MockResponse('', ['http_code' => 500])];
    }

    /**
     * @param Closure(): MockResponse $response
     */
    #[DataProvider('answersThatAreNotAKey')]
    public function testAnAnswerThatIsNotAKeyGivesNothing(Closure $response): void
    {
        self::assertNull($this->lookup([$response()], 'keys.openpgp.org')->find($this->user(), self::ADDRESS));
        self::assertNotContains('https://93.184.215.15/key', $this->requested);
    }

    public function testAKeyServerOnAPrivateAddressIsNotAsked(): void
    {
        $answer = $this->lookup([], resolve: static fn (string $host): array => ['93.184.215.14', '10.0.0.7'])->find($this->user(), self::ADDRESS);

        self::assertNull($answer);
        self::assertSame([], $this->requested);
    }

    public function testTheServersAreAskedInTheConfiguredOrderAndTheFirstUsableKeyWins(): void
    {
        $first  = new Gpg(self::ADDRESS);
        $second = new Gpg(self::ADDRESS);
        $body   = (string) json_encode(['publicKeyArmored' => $first->armored]);

        $answer = $this->lookup([new MockResponse($body), new MockResponse($second->armored)], 'keys.mailvelope.com, keys.openpgp.org')->find($this->user(), self::ADDRESS);

        self::assertSame('keys.mailvelope.com', $answer?->host);
        self::assertSame($first->fingerprint, $answer->keys[0]->fingerprint);
        self::assertCount(1, $this->requested, 'a later server was asked after a usable key');
        self::assertStringStartsWith('https://keys.mailvelope.com/', $this->requested[0]);
    }

    public function testTheSecondServerIsAskedWhenTheFirstHasNothing(): void
    {
        $key = new Gpg(self::ADDRESS);

        $answer = $this->lookup([new MockResponse('', ['http_code' => 404]), new MockResponse((string) json_encode(['publicKeyArmored' => $key->armored]))])->find($this->user(), self::ADDRESS);

        self::assertSame('keys.mailvelope.com', $answer?->host);
        self::assertStringStartsWith('https://keys.openpgp.org/', $this->requested[0]);
        self::assertStringStartsWith('https://keys.mailvelope.com/', $this->requested[1]);
    }

    public function testAFoundKeyIsCachedAndNotAskedForAgain(): void
    {
        $key    = new Gpg(self::ADDRESS);
        $lookup = $this->lookup([new MockResponse($key->armored)], 'keys.openpgp.org');

        $lookup->find($this->user(), self::ADDRESS);
        $again = $lookup->find($this->user(), self::ADDRESS);

        self::assertSame($key->fingerprint, $again?->keys[0]->fingerprint);
        self::assertCount(1, $this->requested);
    }

    public function testNotFindingAnythingIsCachedToo(): void
    {
        $lookup = $this->lookup([]);

        $lookup->find($this->user(), self::ADDRESS);
        $lookup->find($this->user(), self::ADDRESS);

        self::assertCount(2, $this->requested, 'the second find went out again');
    }

    /**
     * One budget for every lookup that leaves the server: a person who spent
     * it on WKD cannot carry on against the key servers.
     */
    public function testTheKeyServersShareTheWkdRateLimit(): void
    {
        $fetcher = $this->fetcher([new MockResponse('', ['http_code' => 404]), new MockResponse('', ['http_code' => 404])], limit: 1);
        $user    = $this->user();

        new WkdLookup($fetcher, new PublicKeyReader())->find($user, self::ADDRESS);

        try {
            $this->servers($fetcher, 'keys.openpgp.org')->find($user, self::ADDRESS);
            self::fail('the key server was asked past the limit');
        } catch (ContactKeyRefused $refused) {
            self::assertSame('contact_keys.lookup.rate_limited', $refused->translationKey);
        }

        self::assertCount(2, $this->requested, 'only the two WKD URLs');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function installationsWithoutKeyServers(): iterable
    {
        yield 'empty'               => [''];
        yield 'only commas'         => [' , '];
        yield 'unknown host names'  => ['pgp.mit.edu, keyserver.ubuntu.com'];
        // Not a host name at all, and certainly not a URL to be fetched.
        yield 'a URL'               => ['https://169.254.169.254/latest'];
    }

    #[DataProvider('installationsWithoutKeyServers')]
    public function testAnInstallationWithoutKnownServersRequestsNothing(string $configured): void
    {
        $lookup = $this->lookup([], $configured);

        self::assertFalse($lookup->isEnabled());
        self::assertNull($lookup->find($this->user(), self::ADDRESS));
        self::assertSame([], $this->requested);
    }

    public function testAnUnknownHostNameIsIgnoredWithALogLineAndTheKnownOnesKept(): void
    {
        $lookup = $this->lookup([], 'pgp.mit.edu, KEYS.openpgp.org');

        self::assertSame(['keys.openpgp.org'], $lookup->hosts());
        self::assertCount(1, $this->log->lines);
        self::assertStringContainsString('pgp.mit.edu', $this->log->lines[0]);
    }

    public function testAPersonWhoSwitchedKeyServersOffIsNeverLookedUpThere(): void
    {
        $user                   = $this->user();
        $user->lookUpKeyServers = false;
        $lookup                 = $this->lookup([new MockResponse((new Gpg(self::ADDRESS))->armored)]);

        self::assertTrue($lookup->isEnabled());
        self::assertFalse($lookup->isEnabledFor($user));
        self::assertNull($lookup->find($user, self::ADDRESS));
        self::assertSame([], $this->requested);
    }

    public function testKeyServersAreOnForAPersonWhoSaidNothing(): void
    {
        self::assertTrue($this->user()->lookUpKeyServers);
    }

    public function testSomethingThatIsNotAnAddressIsRefusedWithoutARequest(): void
    {
        $this->expectException(ContactKeyRefused::class);

        try {
            $this->lookup([])->find($this->user(), 'not an address');
        } finally {
            self::assertSame([], $this->requested);
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * @param list<MockResponse>                   $responses
     * @param (Closure(string): list<string>)|null $resolve
     */
    private function lookup(array $responses, string $configured = 'keys.openpgp.org,keys.mailvelope.com', ?Closure $resolve = null): KeyServerLookup
    {
        return $this->servers($this->fetcher($responses, resolve: $resolve), $configured);
    }

    private function servers(KeyFetcher $fetcher, string $configured): KeyServerLookup
    {
        return new KeyServerLookup(
            $fetcher,
            new PublicKeyReader(),
            [new OpenPgpOrgKeyServer(), new MailvelopeKeyServer()],
            $configured,
            $this->log,
        );
    }

    /**
     * @param list<MockResponse>                   $responses
     * @param (Closure(string): list<string>)|null $resolve
     */
    private function fetcher(array $responses, int $limit = 30, ?Closure $resolve = null): KeyFetcher
    {
        $client = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requested[] = $url;

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 404]);
        });

        return new KeyFetcher(
            $client,
            new ArrayAdapter(),
            new RateLimiterFactory(['id' => 'wkd', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 hour'], new InMemoryStorage()),
            new NullLogger(),
            $resolve ?? static fn (string $host): array => ['93.184.215.14'],
        );
    }

    private function user(): User
    {
        $user = new User();
        $user->email = 'keyserver@example.test';

        return $user;
    }
}
