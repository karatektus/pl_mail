<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Push;

use App\Domain\DTO\Push\ServiceAccount;
use App\Jmap\Push\FcmAccessTokenProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The service-account JWT grant is hand-rolled, so the assertion has to be
 * proved rather than assumed.
 *
 * That is the whole claim here. google/auth was left out deliberately — the
 * grant is fifty lines and the library brings four packages onto a Raspberry
 * Pi — and the price of that decision is that nothing else verifies the
 * assertion until Google does, at which point the only symptom is
 * `invalid_grant` and a device that never rings. So the signature is checked
 * here against the public half of a real generated keypair, not merely
 * inspected for shape: a base64 mistake, a wrong signing input or the wrong
 * digest all produce a well-formed JWT that fails only at Google.
 *
 * `aud` gets its own assertion because it is the field that is wrong in every
 * hand-rolled implementation at least once. It names the token endpoint, not
 * the FCM API, and naming the resource instead is refused as `invalid_grant` —
 * indistinguishable, from the outside, from a bad key.
 *
 * Against a MockHttpClient rather than the container: this class holds no state
 * beyond its cache and touches no database, and the request it captures is the
 * only place the assertion actually exists.
 */
final class FcmAccessTokenProviderTest extends TestCase
{
    /** @var list<array{url:string,body:string}> */
    private array $requests = [];

    public function testTheAssertionVerifiesAgainstTheServiceAccountsKey(): void
    {
        $token = $this->provider()->tokenFor($this->account());

        self::assertSame('ya29.test-access-token', $token);
        self::assertCount(1, $this->requests);
        self::assertSame('https://oauth2.googleapis.com/token', $this->requests[0]['url']);

        [$header, $claims, $signature] = $this->assertionParts();

        self::assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $header);

        $signingInput = substr($this->assertion(), 0, strrpos($this->assertion(), '.') ?: 0);

        self::assertSame(
            1,
            openssl_verify($signingInput, $signature, FirebaseFixture::publicKey(), OPENSSL_ALGO_SHA256),
            'the JWT signature does not verify — Google would answer invalid_grant',
        );

        self::assertSame('push@plmail-test.iam.gserviceaccount.com', $claims['iss']);
        self::assertSame(FcmAccessTokenProvider::SCOPE, $claims['scope']);
        self::assertSame(
            'https://oauth2.googleapis.com/token',
            $claims['aud'],
            'aud must name the token endpoint, not the FCM API',
        );
        self::assertLessThanOrEqual(time(), $claims['iat']);
        self::assertGreaterThan(time(), $claims['exp']);
    }

    /** The grant is the request before the request; making it per push doubles every notification. */
    public function testASecondCallReusesTheCachedTokenRatherThanGrantingAgain(): void
    {
        $provider = $this->provider();

        $provider->tokenFor($this->account());
        $provider->tokenFor($this->account());

        self::assertCount(1, $this->requests);
    }

    /**
     * A refused grant is null and not an exception: the only caller is a push,
     * and a push that cannot go out is a missing notification rather than a
     * request that should fail.
     */
    public function testARefusedGrantIsNullRatherThanAThrow(): void
    {
        $provider = $this->provider(new MockResponse(
            (string) json_encode(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.']),
            ['http_code' => 400],
        ));

        self::assertNull($provider->tokenFor($this->account()));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function provider(?MockResponse $response = null): FcmAccessTokenProvider
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = ['url' => $url, 'body' => (string) ($options['body'] ?? '')];

            return $response ?? new MockResponse((string) json_encode([
                'access_token' => 'ya29.test-access-token',
                'expires_in'   => 3600,
                'token_type'   => 'Bearer',
            ]));
        });

        return new FcmAccessTokenProvider($client, new NullLogger());
    }

    private function account(): ServiceAccount
    {
        return ServiceAccount::fromJson(FirebaseFixture::serviceAccountJson());
    }

    private function assertion(): string
    {
        parse_str($this->requests[0]['body'], $form);

        self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type'] ?? null);
        self::assertIsString($form['assertion'] ?? null);

        return $form['assertion'];
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:string}
     */
    private function assertionParts(): array
    {
        $segments = explode('.', $this->assertion());

        self::assertCount(3, $segments, 'a JWT is three base64url segments');

        $decode = static fn (string $segment): string => (string) base64_decode(strtr($segment, '-_', '+/'), true);

        // Padding must be absent (RFC 7515 §2); a padded segment is rejected as
        // malformed rather than as unauthorised, which nothing downstream says.
        foreach ($segments as $segment) {
            self::assertStringNotContainsString('=', $segment);
        }

        return [
            json_decode($decode($segments[0]), true, 512, JSON_THROW_ON_ERROR),
            json_decode($decode($segments[1]), true, 512, JSON_THROW_ON_ERROR),
            $decode($segments[2]),
        ];
    }
}
