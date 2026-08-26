<?php

declare(strict_types=1);

namespace App\Jmap\Push;

use App\Domain\DTO\Push\ServiceAccount;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The OAuth 2.0 bearer token FCM HTTP v1 wants, obtained by the service-account
 * JWT grant (RFC 7523): sign an assertion with the account's private key, POST
 * it to Google's token endpoint, get an access token back.
 *
 * **Hand-rolled rather than pulled in from google/auth**, deliberately. The
 * whole grant is a JSON header, a JSON claim set, one openssl_sign() and one
 * form POST — fifty lines — and the library that does it drags in guzzle, psr7,
 * a cache abstraction and their dependencies. This product's target is a
 * Raspberry Pi with a repository kept slim on purpose, and the cost of the
 * dependency is paid by every install forever while the cost of these fifty
 * lines is paid once. Nothing cryptographic is invented here: ext-openssl signs
 * and Google verifies, exactly as the library's own signer does.
 *
 * **Cached until it expires, minus a minute.** A token lasts an hour and every
 * StateChange would otherwise open with a round trip to Google before the one
 * that matters — two requests per notification on a connection that may be a
 * home DSL line. The skew exists because the token is checked at Google against
 * Google's clock: a token handed out with four seconds left is refused on
 * arrival, and the retry looks like an authentication failure.
 *
 * Keyed by client_email, so rotating the service account through the admin page
 * does not serve a token minted for the previous one.
 *
 * Docs: https://developers.google.com/identity/protocols/oauth2/service-account
 */
final class FcmAccessTokenProvider
{
    /** The one scope messages:send needs. */
    public const string SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** RFC 7523 §2.1 — what the token endpoint is being asked for. */
    private const string GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    /**
     * The assertion's own lifetime. Google's ceiling is an hour and there is no
     * reason to ask for less: the assertion is spent immediately.
     */
    private const int ASSERTION_LIFETIME = 3600;

    /**
     * How early a cached token is treated as expired, to cover clock skew and
     * the flight time of the request it would be used on.
     */
    private const int EXPIRY_MARGIN = 60;

    /** @var array<string,array{token:string,expiresAt:int}> keyed by client_email */
    private array $cache = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface     $logger,
    ) {}

    /**
     * A bearer token for this service account, or null when Google refused the
     * grant or could not be reached.
     *
     * Null rather than an exception: the only caller is a push send, and a push
     * that cannot go out is a notification the user does not get rather than a
     * request that should fail. The reason is logged where an admin will look.
     */
    public function tokenFor(ServiceAccount $account): ?string
    {
        $cached = $this->cache[$account->clientEmail] ?? null;

        if (null !== $cached && $cached['expiresAt'] > time()) {
            return $cached['token'];
        }

        $assertion = $this->assertion($account);

        if (null === $assertion) {
            return null;
        }

        try {
            $response = $this->http->request('POST', $account->tokenUri, [
                'body' => [
                    'grant_type' => self::GRANT_TYPE,
                    'assertion'  => $assertion,
                ],
            ]);

            $status = $response->getStatusCode();
            // false: a 4xx must be read for its error body rather than thrown,
            // because "invalid_grant" and "invalid_scope" are the two answers
            // that tell an admin their key is wrong and both arrive as a 400.
            $body = $response->toArray(false);
        } catch (HttpException|\JsonException $exception) {
            $this->logger->error('FCM: could not reach the Google token endpoint.', [
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return null;
        }

        $token = $body['access_token'] ?? null;

        if (200 !== $status || false === is_string($token) || '' === $token) {
            $this->logger->error('FCM: Google refused the service-account grant.', [
                'status' => $status,
                'error'  => $body['error'] ?? null,
                'description' => $body['error_description'] ?? null,
            ]);

            return null;
        }

        // Google always sends expires_in; the fallback is there so a response
        // without it caches for a sane interval rather than for zero seconds,
        // which would mean a grant per push.
        $expiresIn = $body['expires_in'] ?? null;
        $lifetime  = is_int($expiresIn) ? $expiresIn : self::ASSERTION_LIFETIME;

        $this->cache[$account->clientEmail] = [
            'token'     => $token,
            'expiresAt' => time() + max(0, $lifetime - self::EXPIRY_MARGIN),
        ];

        return $token;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The signed JWT, or null when the stored private key will not load or will
     * not sign — which is the one failure a pasted credential can still have
     * after ServiceAccount::fromJson() has accepted it, because only openssl
     * can answer whether a PEM block is a key.
     */
    private function assertion(ServiceAccount $account): ?string
    {
        $issuedAt = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $account->clientEmail,
            'scope' => self::SCOPE,
            // The audience is the token endpoint itself, NOT the FCM API. A
            // JWT whose aud names the resource is refused with invalid_grant,
            // which reads like a bad key.
            'aud'   => $account->tokenUri,
            'iat'   => $issuedAt,
            'exp'   => $issuedAt + self::ASSERTION_LIFETIME,
        ];

        $signingInput = $this->base64Url($this->json($header)) . '.' . $this->base64Url($this->json($claims));

        $key = openssl_pkey_get_private($account->privateKey);

        if (false === $key) {
            $this->logger->error('FCM: the service account\'s private_key is not a readable PEM private key.', [
                'clientEmail' => $account->clientEmail,
            ]);

            return null;
        }

        $signature = '';

        if (false === openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            $this->logger->error('FCM: could not sign the token assertion with the stored private key.', [
                'clientEmail' => $account->clientEmail,
            ]);

            return null;
        }

        return $signingInput . '.' . $this->base64Url($signature);
    }

    /**
     * JWT segments are base64url with the padding removed (RFC 7515 §2). A
     * standard base64 assertion is rejected as malformed rather than as
     * unauthorised, so the difference is not one anything downstream explains.
     */
    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param array<string,mixed> $value
     */
    private function json(array $value): string
    {
        // Unescaped slashes: a client_email and a scope URL both contain them,
        // and while "\/" is legal JSON it makes the assertion differ byte for
        // byte from every reference implementation, which turns a debugging
        // session into a comparison of escape conventions.
        return (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
