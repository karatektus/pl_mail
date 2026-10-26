<?php

declare(strict_types=1);

namespace App\Service\AddressBook\Google;

use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\CalendarSyncThrottledException;
use App\Domain\Exception\OAuthGrantRevokedException;
use App\Entity\Mail\Account;
use App\Service\Contacts\ContactPhoto;
use App\Service\Contacts\ContactPhotoStore;
use App\Service\OAuth\OAuthTokenManager;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Every request made to the Google People API, and the one place a Google
 * failure becomes a CalendarSyncException — GoogleCalendarApiClient's
 * counterpart for contacts, and classified the same way.
 *
 * Classification, and why each one:
 *
 *   **401, and 403 for a missing scope → permanent, phrased as the fix.** The
 *   contacts permission is asked for separately (see MailProvider::
 *   contactScopes()), and a grant can lose it — a user who unticked it, an
 *   admin who withdrew it. Retrying cannot help; the book's error line says
 *   what to do.
 *   **403 SERVICE_DISABLED (accessNotConfigured) → permanent, addressed to
 *   the administrator.** The People API is not enabled in the Cloud project
 *   behind Mail sign-in. Sign-in, mail and calendars work, and only the
 *   contacts calls are refused. Google's own text names the project and a
 *   console link for whoever owns it. Shown on a user's address book, it reads as
 *   something they did wrong, and reconnecting cannot fix it. So the line
 *   says who can fix it.
 *   **429, and 403 RESOURCE_EXHAUSTED → throttled.** The People API has a
 *   per-user quota, and a full sync's first page an extra fixed one.
 *   **400 with EXPIRED_SYNC_TOKEN → resync required.** A sync token lives seven
 *   days after the full sync; past that the book is read from scratch.
 *   **400 FAILED_PRECONDITION otherwise → a conflict, reported as 412.** That
 *   is what a stale etag on updateContact answers: the contact changed at
 *   Google since it was last read. AddressBookSync reads a 412 as "the server
 *   wins", whatever the provider spelled it as.
 *   **404 → permanent** (and a delete tolerates it: already gone is done).
 *   **Everything else — 5xx, a transport failure — the unclassified base**, so
 *   the transport's retries are what try again.
 *
 * Messages carry Google's own wording and never a URL or a token.
 *
 * Docs: https://developers.google.com/people/api/rest
 */
final readonly class GooglePeopleClient
{
    public const string BASE = 'https://people.googleapis.com/v1';

    /** The 403 statuses and reasons that are a quota rather than a refusal. */
    private const array THROTTLED = ['RESOURCE_EXHAUSTED', 'rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'RATE_LIMIT_EXCEEDED'];

    /** What Google answers when the People API is not enabled in the project. */
    private const array API_DISABLED = ['SERVICE_DISABLED', 'accessNotConfigured'];

    public const string API_DISABLED_HINT = 'Google refuses contacts because the People API is not enabled for this plMail installation. Mail and calendars are not affected. Your plMail administrator needs to enable the Google People API in the Google Cloud project used for mail sign-in; contacts then sync on their own.';

    /** What Google answers when the token does not carry the contacts scope. */
    private const array SCOPE = ['ACCESS_TOKEN_SCOPE_INSUFFICIENT', 'insufficientPermissions'];

    public const string RECONNECT_HINT = 'plMail is not allowed to use this Google account\'s contacts. Turn on contact sync for it again in Settings → Contacts and allow access.';

    public function __construct(
        private HttpClientInterface $httpClient,
        private OAuthTokenManager   $tokens,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     *
     * @throws CalendarSyncException
     */
    public function get(Account $account, string $path, array $query, string $what): array
    {
        return $this->send($account, 'GET', $path, ['query' => $query], $what);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     *
     * @throws CalendarSyncException
     */
    public function write(Account $account, string $method, string $path, array $query, array $body, string $what): array
    {
        return $this->send($account, $method, $path, ['query' => $query, 'json' => $body], $what);
    }

    /**
     * A request with no body — deleteContactPhoto, which answers the Person.
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     *
     * @throws CalendarSyncException
     */
    public function call(Account $account, string $method, string $path, array $query, string $what): array
    {
        return $this->send($account, $method, $path, ['query' => $query], $what);
    }

    /**
     * A contact photo, from the URL a Person's photos[] gives. Only from
     * Google's own image host, and without the account's token: the URL is
     * one the API handed us, but nothing else of ours goes to it. Asked at
     * MAX_DIMENSION, the size the photo store keeps. Null when Google says
     * it is gone.
     *
     * @throws CalendarSyncException
     */
    public function photo(string $url): ?ContactPhoto
    {
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));

        if ('https' !== strtolower((string) parse_url($url, \PHP_URL_SCHEME)) || false === str_ends_with($host, '.googleusercontent.com')) {
            throw new CalendarSyncPermanentException('Google gave a contact photo address that is not on its image host.');
        }

        $url = (preg_replace('/=s\d+(-[a-z0-9-]+)?$/i', '', $url) ?? $url) . '=s' . ContactPhotoStore::MAX_DIMENSION;

        try {
            $response = $this->httpClient->request('GET', $url, ['max_redirects' => 0]);
            $status   = $response->getStatusCode();

            if (404 === $status || 410 === $status) {
                return null;
            }

            if (200 !== $status) {
                throw new CalendarSyncException(sprintf('Google failed while reading a contact photo (%d).', $status), $status);
            }

            $bytes = $response->getContent(false);
            $mime  = strtolower(trim(explode(';', $response->getHeaders(false)['content-type'][0] ?? '')[0]));
        } catch (CalendarSyncException $e) {
            throw $e;
        } catch (HttpException $e) {
            throw new CalendarSyncException('Google could not be reached while reading a contact photo.', 0, $e);
        }

        return '' === $bytes || strlen($bytes) > ContactPhotoStore::MAX_BYTES ? null : new ContactPhoto($bytes, $mime);
    }

    /**
     * Idempotent: a contact already gone is a contact deleted.
     *
     * @throws CalendarSyncException
     */
    public function delete(Account $account, string $path, string $what): void
    {
        $this->send($account, 'DELETE', $path, [], $what, [404, 410]);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<int>            $tolerate
     *
     * @return array<string, mixed>
     *
     * @throws CalendarSyncException
     */
    private function send(Account $account, string $method, string $path, array $options, string $what, array $tolerate = []): array
    {
        $options['auth_bearer'] = $this->token($account);

        try {
            $response = $this->httpClient->request($method, self::BASE . $path, $options);
            $status   = $response->getStatusCode();

            if (true === in_array($status, $tolerate, true)) {
                return [];
            }

            if (200 > $status || 300 <= $status) {
                throw $this->classify($response, $status, $what);
            }

            if (204 === $status) {
                return [];
            }

            $content = $response->getContent(false);
        } catch (CalendarSyncException $e) {
            throw $e;
        } catch (HttpException $e) {
            throw new CalendarSyncException(sprintf('Google could not be reached while %s.', $what), 0, $e);
        }

        if ('' === trim($content)) {
            return [];
        }

        $decoded = json_decode($content, true);

        if (false === is_array($decoded)) {
            throw new CalendarSyncException(sprintf('Google answered something that is not JSON while %s.', $what), $status);
        }

        return $decoded;
    }

    /** A refresh Google refuses is a grant that is gone; anything else may clear. */
    private function token(Account $account): string
    {
        try {
            return $this->tokens->getValidAccessToken($account);
        } catch (OAuthGrantRevokedException $e) {
            throw new CalendarSyncPermanentException('Google would not renew the sign-in for this account. Reconnect it in the account settings.', 401, $e);
        } catch (\Throwable $e) {
            throw new CalendarSyncException('Google could not renew the sign-in for this account.', 401, $e);
        }
    }

    private function classify(ResponseInterface $response, int $status, string $what): CalendarSyncException
    {
        [$code, $reasons, $message] = $this->errorOf($response);
        $all                        = [$code, ...$reasons];
        $said                       = '' === $message ? $code : $message;

        if (401 === $status || (403 === $status && [] !== array_intersect($all, self::SCOPE))) {
            return new CalendarSyncPermanentException(self::RECONNECT_HINT, $status);
        }

        if (403 === $status && [] !== array_intersect($all, self::API_DISABLED)) {
            return new CalendarSyncPermanentException(self::API_DISABLED_HINT, $status);
        }

        if (429 === $status || (403 === $status && [] !== array_intersect($all, self::THROTTLED))) {
            return new CalendarSyncThrottledException(sprintf('Google is rate-limiting this account while %s.', $what), $status, $this->retryAfter($response));
        }

        if (true === in_array('EXPIRED_SYNC_TOKEN', $all, true) || 410 === $status) {
            return new CalendarResyncRequiredException('Google\'s sync position for these contacts has expired, so they are being read again from scratch.', 410);
        }

        if (400 === $status && (true === in_array('FAILED_PRECONDITION', $all, true) || true === in_array('failedPrecondition', $all, true))) {
            return new CalendarResyncRequiredException('The contact changed at Google since plMail last read it, so the edit was not applied.', 412);
        }

        if (400 === $status || 403 === $status || 404 === $status) {
            return new CalendarSyncPermanentException(sprintf('Google refused this while %s: %s', $what, '' === $said ? 'no reason given' : $said), $status);
        }

        return new CalendarSyncException(sprintf('Google failed while %s (%d): %s', $what, $status, '' === $said ? 'no reason given' : $said), $status);
    }

    /**
     * The google.rpc.Status envelope: error.status, the reasons in
     * error.details[] and the older error.errors[], and the message.
     *
     * @return array{0: string, 1: list<string>, 2: string}
     */
    private function errorOf(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode($response->getContent(false), true);
        } catch (HttpException) {
            return ['', [], ''];
        }

        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $reasons = [];

        foreach ([...(is_array($error['details'] ?? null) ? $error['details'] : []), ...(is_array($error['errors'] ?? null) ? $error['errors'] : [])] as $detail) {
            if (true === is_array($detail) && true === is_string($detail['reason'] ?? null)) {
                $reasons[] = $detail['reason'];
            }
        }

        return [
            is_string($error['status'] ?? null) ? $error['status'] : '',
            $reasons,
            mb_substr(trim(is_string($error['message'] ?? null) ? $error['message'] : ''), 0, 200),
        ];
    }

    private function retryAfter(ResponseInterface $response): ?int
    {
        try {
            $header = $response->getHeaders(false)['retry-after'][0] ?? null;
        } catch (HttpException) {
            return null;
        }

        return true === ctype_digit((string) $header) ? (int) $header : null;
    }
}
