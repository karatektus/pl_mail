<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Domain\Exception\ContactKeyRefused;
use App\Entity\User\User;
use App\Service\Crypto\KeyFetcher;
use App\Service\Crypto\PublicKeyReader;
use App\Service\Crypto\WkdLookup;
use App\Tests\Support\Crypto\Gpg;
use Closure;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The WKD proxy fetches URLs derived from what a person typed, so it is
 * tested as what it could become: a way to make the server request things.
 *
 * The URL is never the person's — only the two WKD shapes, HTTPS, from a
 * validated domain — and the request does not follow redirects, does not go
 * to private addresses, gives up on a body too big to be a key, and is
 * cached and rate-limited. A key that comes back without a user ID for the
 * address asked about is not that address's key.
 *
 * Against MockHttpClient and an injected resolver: no test asks real DNS or
 * the internet. The keys are real, from gpg, because "has a user ID for the
 * address" is gpg's verdict.
 */
final class WkdLookupTest extends TestCase
{
    private const string ADDRESS = 'anna@example.org';

    /** @var list<string> */
    private array $requested = [];

    public function testTheHashIsZBase32OfTheSha1OfTheLowerCasedLocalPart(): void
    {
        // The worked example of draft-koch-openpgp-webkey-service §3.1.
        $urls = WkdLookup::urls('Joe.Doe@Example.ORG');

        self::assertSame('https://openpgpkey.example.org/.well-known/openpgpkey/example.org/hu/iy9q119eutrkn8s1mk4r39qejnbu3n5q?l=Joe.Doe', $urls['advanced'] ?? null);
        self::assertSame('https://example.org/.well-known/openpgpkey/hu/iy9q119eutrkn8s1mk4r39qejnbu3n5q?l=Joe.Doe', $urls['direct'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesThatAreNotLookedUp(): iterable
    {
        yield 'an IP literal'          => ['anna@127.0.0.1'];
        yield 'a bracketed literal'    => ['anna@[10.0.0.1]'];
        yield 'a bare container name'  => ['anna@database'];
        yield 'a path in the domain'   => ['anna@example.org/evil'];
        yield 'a port in the domain'   => ['anna@example.org:8080'];
        yield 'userinfo trickery'      => ['anna@evil.test@example.org'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('addressesThatAreNotLookedUp')]
    public function testAnAddressThatIsNotAPublicDomainIsRefusedWithoutARequest(string $address): void
    {
        try {
            $this->lookup([])->find($this->user(), $address);
            self::fail('looked up ' . $address);
        } catch (ContactKeyRefused $refused) {
            self::assertSame('contact_keys.lookup.invalid_address', $refused->translationKey);
        }

        self::assertSame([], $this->requested);
    }

    public function testTheAdvancedMethodIsAskedFirst(): void
    {
        $key  = new Gpg(self::ADDRESS);
        $keys = $this->lookup([new MockResponse($key->binary)])->find($this->user(), self::ADDRESS);

        self::assertSame([$key->fingerprint], array_map(static fn ($info): string => $info->fingerprint, $keys));
        self::assertStringStartsWith('https://openpgpkey.example.org/', $this->requested[0]);
        self::assertCount(1, $this->requested);
    }

    public function testTheDirectMethodIsAskedWhenTheAdvancedOneHasNothing(): void
    {
        $key  = new Gpg(self::ADDRESS);
        $keys = $this->lookup([new MockResponse('', ['http_code' => 404]), new MockResponse($key->binary)])->find($this->user(), self::ADDRESS);

        self::assertCount(1, $keys);
        self::assertStringStartsWith('https://example.org/.well-known/openpgpkey/hu/', $this->requested[1]);
    }

    /** Every request is HTTPS, whatever happens. */
    public function testOnlyHttpsIsEverRequested(): void
    {
        $this->lookup([new MockResponse('', ['http_code' => 404]), new MockResponse('', ['http_code' => 404])])->find($this->user(), self::ADDRESS);

        self::assertCount(2, $this->requested);

        foreach ($this->requested as $url) {
            self::assertStringStartsWith('https://', $url);
        }
    }

    public function testAHostOnAPrivateAddressIsNotAsked(): void
    {
        $keys = $this->lookup([], static fn (string $host): array => ['10.0.0.7'])->find($this->user(), self::ADDRESS);

        self::assertSame([], $keys);
        self::assertSame([], $this->requested);
    }

    public function testAHostWithAnyPrivateAddressAmongItsPublicOnesIsNotAsked(): void
    {
        $this->lookup([], static fn (string $host): array => ['93.184.215.14', '::ffff:169.254.169.254'])->find($this->user(), self::ADDRESS);

        self::assertSame([], $this->requested);
    }

    /**
     * A redirect is how a hostile domain would point the server elsewhere.
     *
     * What this can pin is that a 3xx answer is not a key and nothing goes to
     * where it points. It cannot pin `max_redirects: 0` itself: redirects are
     * followed by NoPrivateNetworkHttpClient, which never lets the client
     * beneath it follow one, and MockHttpClient below that follows none
     * whatever it is told — so the test passes with redirects allowed too.
     * The option is in KeyFetcher::get(), with its reason.
     */
    public function testARedirectToAnotherHostIsNotFollowed(): void
    {
        $redirect = new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://93.184.215.15/key']]);

        $keys = $this->lookup([$redirect, new MockResponse('', ['http_code' => 404])])->find($this->user(), self::ADDRESS);

        self::assertSame([], $keys);
        self::assertNotContains('https://93.184.215.15/key', $this->requested);

    }

    public function testABodyTooLargeToBeAKeyIsDropped(): void
    {
        $huge = str_repeat('A', PublicKeyReader::MAX_BYTES + 1);

        $keys = $this->lookup([new MockResponse($huge), new MockResponse($huge)])->find($this->user(), self::ADDRESS);

        self::assertSame([], $keys);
    }

    public function testAKeyWithoutAUserIdForTheAddressIsNotReturned(): void
    {
        $other = new Gpg('somebody@example.org');

        self::assertSame([], $this->lookup([new MockResponse($other->binary)])->find($this->user(), self::ADDRESS));
    }

    public function testAnAnswerIsCachedAndNotAskedForAgain(): void
    {
        $key    = new Gpg(self::ADDRESS);
        $lookup = $this->lookup([new MockResponse($key->binary)]);

        $lookup->find($this->user(), self::ADDRESS);
        $again = $lookup->find($this->user(), self::ADDRESS);

        self::assertCount(1, $again);
        self::assertCount(1, $this->requested);
    }

    public function testNotFindingAnythingIsCachedToo(): void
    {
        $lookup = $this->lookup([new MockResponse('', ['http_code' => 404]), new MockResponse('', ['http_code' => 404])]);

        $lookup->find($this->user(), self::ADDRESS);
        $lookup->find($this->user(), self::ADDRESS);

        self::assertCount(2, $this->requested, 'the second find went out again');
    }

    public function testLookupsThatGoOutAreRateLimitedPerPerson(): void
    {
        $lookup = $this->lookup([new MockResponse('', ['http_code' => 404]), new MockResponse('', ['http_code' => 404])], limit: 1);

        $lookup->find($this->user(), self::ADDRESS);

        $this->expectException(ContactKeyRefused::class);

        $lookup->find($this->user(), 'bob@example.org');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * @param list<MockResponse>                  $responses
     * @param (Closure(string): list<string>)|null $resolve
     */
    private function lookup(array $responses, ?Closure $resolve = null, int $limit = 30): WkdLookup
    {
        $client = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requested[] = $url;

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 404]);
        });

        return new WkdLookup(new KeyFetcher(
            $client,
            new ArrayAdapter(),
            new RateLimiterFactory(['id' => 'wkd', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 hour'], new InMemoryStorage()),
            new NullLogger(),
            $resolve ?? static fn (string $host): array => ['93.184.215.14'],
        ), new PublicKeyReader());
    }

    private function user(): User
    {
        $user = new User();
        $user->email = 'wkd@example.test';

        return $user;
    }
}
