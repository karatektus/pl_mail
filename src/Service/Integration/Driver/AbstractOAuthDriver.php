<?php

declare(strict_types=1);

namespace App\Service\Integration\Driver;

use App\Domain\Exception\IntegrationException;
use App\Domain\Interface\IntegrationDriverInterface;
use App\Entity\Integration\Integration;
use App\Service\Integration\IntegrationTokenManager;
use DateTimeImmutable;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * What every OAuth-backed driver needs: a bearer token, and failures turned
 * into messages a person can read.
 *
 * Exists because the four SaaS drivers were otherwise going to carry four
 * copies of "fetch a token, set auth_bearer, map a status code to a sentence"
 * — the same duplication the two self-hosted drivers already have between
 * them, and which is worth not repeating twice over.
 *
 * Status mapping is shared but not fixed: label() names the service in the
 * message, and a driver overrides messageForStatus() where a code means
 * something specific to it (Dropbox answering 409 for "already shared", for
 * instance).
 */
abstract class AbstractOAuthDriver implements IntegrationDriverInterface
{
    public function __construct(
        protected readonly HttpClientInterface     $httpClient,
        protected readonly IntegrationTokenManager $tokens,
    ) {
    }

    /** The service's name, as it should appear in an error shown to a user. */
    abstract protected function label(): string;

    /**
     * Most of these services offer no per-file share link worth the side
     * effects, so null is the sensible default and only the ones that can
     * override it.
     */
    public function shareLink(Integration $integration, string $fileId): ?string
    {
        return null;
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $options
     *
     * @throws IntegrationException
     */
    protected function request(
        Integration $integration,
        string $method,
        string $url,
        array $options = [],
        bool $throwOnError = true,
    ): ResponseInterface {
        $options['auth_bearer'] = $this->tokens->getValidAccessToken($integration);

        try {
            $response = $this->httpClient->request($method, $url, $options);

            if (true === $throwOnError) {
                $status = $response->getStatusCode();

                if ($status >= 400) {
                    throw new IntegrationException($this->messageForStatus($status, $response), $status);
                }
            }

            return $response;
        } catch (IntegrationException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }
    }

    /**
     * @param array<string,mixed> $options
     *
     * @return array<mixed>
     *
     * @throws IntegrationException
     */
    protected function json(Integration $integration, string $method, string $url, array $options = []): array
    {
        $response = $this->request($integration, $method, $url, $options);

        try {
            return $response->toArray();
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }
    }

    protected function translate(HttpExceptionInterface $e): IntegrationException
    {
        $status = method_exists($e, 'getResponse') ? $e->getResponse()->getStatusCode() : 0;

        return new IntegrationException($this->messageForStatus($status, null), $status, $e);
    }

    protected function messageForStatus(int $status, ?ResponseInterface $response): string
    {
        return match (true) {
            // A 401 here means the token was refused despite being fresh —
            // revoked consent, or a scope the app no longer holds. Either way
            // reconnecting is the fix, which is different advice from "check
            // your password" on an app-password provider.
            401 === $status => sprintf('%s refused the connection — reconnect it.', $this->label()),
            // A 403 is TWO different things at Google, and the difference is
            // only in the body. `userRateLimitExceeded`, `rateLimitExceeded`
            // and `dailyLimitExceeded` are quota — a burst of saves, or a
            // share-link action issuing two calls per file — and telling
            // somebody their connection is missing a permission sends them to
            // re-grant a permission they already have, on a connection that
            // was working a minute earlier and will work again in a minute.
            403 === $status && true === $this->isQuota($response)
                => sprintf('%s is rate-limiting us. Try again shortly.', $this->label()),
            403 === $status => sprintf('%s denied access. The connection may be missing a permission.', $this->label()),
            404 === $status => sprintf('%s could not find that item.', $this->label()),
            429 === $status => sprintf('%s is rate-limiting us. Try again shortly.', $this->label()),
            507 === $status => sprintf('The %s account is out of storage space.', $this->label()),
            $status >= 500  => sprintf('%s reported a server error.', $this->label()),
            0 === $status   => sprintf('Could not reach %s.', $this->label()),
            default         => sprintf('%s returned an unexpected response (%d).', $this->label(), $status),
        };
    }

    /**
     * Whether a 403 is quota rather than permission.
     *
     * Google answers 403 for both, and the reason code is the only thing that
     * separates them. GmailApiClient and GoogleCalendarApiClient have each kept
     * their own list of these for the same reason; this is the third, and it is
     * here rather than shared because a driver that is not Google should not
     * inherit Google's vocabulary.
     *
     * Conservative on anything it cannot read: a body that is missing,
     * truncated, or an HTML page from a proxy answers false, which keeps the
     * existing wording. Guessing "quota" on an unreadable body would hide a
     * real permission problem behind "try again shortly" for ever.
     */
    protected function isQuota(?ResponseInterface $response): bool
    {
        if (null === $response) {
            return false;
        }

        try {
            $body = $response->getContent(false);
        } catch (\Throwable) {
            return false;
        }

        $haystack = strtolower($body);

        foreach (['userratelimitexceeded', 'ratelimitexceeded', 'dailylimitexceeded', 'sharingratelimitexceeded'] as $code) {
            if (true === str_contains($haystack, $code)) {
                return true;
            }
        }

        return false;
    }

    // ── Parsing helpers ───────────────────────────────────────────────────────

    protected function parseDate(mixed $raw): ?DateTimeImmutable
    {
        if (false === is_string($raw) || '' === $raw) {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }

    /** The bare MIME type, without the charset services like to append. */
    protected function bareMime(?string $contentType, string $fallback): string
    {
        if (null === $contentType || '' === $contentType) {
            return $fallback;
        }

        return trim(explode(';', $contentType)[0]);
    }
}
