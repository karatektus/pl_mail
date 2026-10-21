<?php

declare(strict_types=1);

namespace App\Service\AddressBook\Graph;

use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\CalendarSyncThrottledException;
use App\Domain\Exception\OAuthGrantRevokedException;
use App\Entity\Mail\Account;
use App\Service\Contacts\ContactPhoto;
use App\Service\Contacts\ContactPhotoStore;
use App\Service\OAuth\OAuthTokenManager;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Every Microsoft Graph request about contacts, and where a Graph failure
 * becomes a CalendarSyncException — classified as GraphCalendarSyncDriver
 * classifies its own:
 *
 *   - **410, or `syncStateNotFound`** → resync required. The delta link has
 *     aged out of Exchange's token cache, or Graph reset the sync.
 *   - **412** → a conflict: the contact changed in Outlook since it was read.
 *   - **429, 503** → throttled, honouring Retry-After in seconds.
 *   - **401, and a 403 about access** → permanent, phrased as the fix.
 *   - **400, 404** → permanent. Everything else, the unclassified base.
 *
 * Every call asks for immutable ids (`Prefer: IdType="ImmutableId"`), so a
 * contact moved between folders in Outlook keeps the id plMail stored.
 *
 * Messages never carry the URL: a resumed delta call holds the whole token.
 */
final readonly class GraphContactsClient
{
    public const string ME = 'https://graph.microsoft.com/v1.0/me';

    public const string RECONNECT_HINT = 'Microsoft refused contacts access for this account. Reconnect it in the account settings and allow contacts permissions.';

    private const array AUTHORIZATION_CODES = [
        'erroraccessdenied',
        'accessdenied',
        'authorization_requestdenied',
        'invalidauthenticationtoken',
        'insufficientscope',
        'unauthorizedaccess',
    ];

    public function __construct(
        // The scoped client: it retries a GET that Graph answers with a 5xx.
        #[Target('graph.client')]
        private HttpClientInterface $httpClient,
        private OAuthTokenManager   $tokens,
    ) {
    }

    /**
     * Follow @odata.nextLink to the end of one round, answering every item
     * and the delta link the round ended on (null for a plain listing).
     *
     * @param array<string, mixed> $query only for the first request; Graph
     *                                    encodes it into the links after
     *
     * @return array{items: list<array<string, mixed>>, deltaLink: ?string}
     *
     * @throws CalendarSyncException
     */
    public function collect(Account $account, string $url, array $query, string $what): array
    {
        $items     = [];
        $deltaLink = null;
        $next      = $url;
        $first     = true;

        for ($page = 0; null !== $next; ++$page) {
            if ($page >= 500) {
                throw new CalendarSyncException(sprintf('Microsoft Graph kept answering with more pages while %s.', $what));
            }

            $body  = $this->call($account, 'GET', $next, true === $first && [] !== $query ? ['query' => $query] : [], $what);
            $first = false;

            foreach (is_array($body['value'] ?? null) ? $body['value'] : [] as $item) {
                if (true === is_array($item)) {
                    $items[] = $item;
                }
            }

            $link = $body['@odata.nextLink'] ?? null;
            $next = is_string($link) && '' !== $link ? $link : null;

            if (is_string($body['@odata.deltaLink'] ?? null) && '' !== $body['@odata.deltaLink']) {
                $deltaLink = $body['@odata.deltaLink'];
            }
        }

        return ['items' => $items, 'deltaLink' => $deltaLink];
    }

    /**
     * @param array<string, mixed> $options
     * @param list<int>            $tolerate statuses answered with an empty body
     *
     * @return array<string, mixed>
     *
     * @throws CalendarSyncException
     */
    public function call(Account $account, string $method, string $url, array $options, string $what, array $tolerate = []): array
    {
        $options['auth_bearer']       = $this->token($account);
        $options['headers']           = ($options['headers'] ?? []) + ['Prefer' => 'IdType="ImmutableId"'];

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status   = $response->getStatusCode();

            if (true === in_array($status, $tolerate, true)) {
                return [];
            }

            if (200 > $status || 300 <= $status) {
                throw $this->translate($response, $status, $what);
            }

            if (204 === $status) {
                return [];
            }

            $content = $response->getContent(false);
        } catch (CalendarSyncException $e) {
            throw $e;
        } catch (HttpException $e) {
            throw new CalendarSyncException(sprintf('Microsoft Graph could not be reached while %s.', $what), 0, $e);
        }

        $decoded = '' === trim($content) ? [] : json_decode($content, true);

        if (false === is_array($decoded)) {
            throw new CalendarSyncException(sprintf('Microsoft Graph answered something that is not JSON while %s.', $what), $status);
        }

        return $decoded;
    }

    /**
     * A contact's photo bytes, from `/contacts/{id}/photo/$value`; null when
     * it has none (Graph answers 404 for that).
     *
     * @throws CalendarSyncException
     */
    public function photo(Account $account, string $contactId): ?ContactPhoto
    {
        $url = self::ME . '/contacts/' . rawurlencode($contactId) . '/photo/$value';

        try {
            $response = $this->httpClient->request('GET', $url, [
                'auth_bearer' => $this->token($account),
                'headers'     => ['Prefer' => 'IdType="ImmutableId"'],
            ]);
            $status = $response->getStatusCode();

            if (404 === $status) {
                return null;
            }

            if (200 !== $status) {
                throw $this->translate($response, $status, 'reading a contact photo');
            }

            $bytes = $response->getContent(false);
            $mime  = strtolower(trim(explode(';', $response->getHeaders(false)['content-type'][0] ?? '')[0]));
        } catch (CalendarSyncException $e) {
            throw $e;
        } catch (HttpException $e) {
            throw new CalendarSyncException('Microsoft Graph could not be reached while reading a contact photo.', 0, $e);
        }

        return '' === $bytes || strlen($bytes) > ContactPhotoStore::MAX_BYTES ? null : new ContactPhoto($bytes, $mime);
    }

    /**
     * Replace a contact's photo. Graph has no way to remove one: the
     * profilePhoto delete is for users and groups only, not Outlook contacts.
     *
     * @throws CalendarSyncException
     */
    public function putPhoto(Account $account, string $contactId, ContactPhoto $photo): void
    {
        $this->call($account, 'PUT', self::ME . '/contacts/' . rawurlencode($contactId) . '/photo/$value', [
            'headers' => ['Content-Type' => $photo->mime],
            'body'    => $photo->bytes,
        ], 'saving a contact photo');
    }

    private function token(Account $account): string
    {
        try {
            return $this->tokens->getValidAccessToken($account);
        } catch (OAuthGrantRevokedException $e) {
            throw new CalendarSyncPermanentException('Microsoft would not renew the sign-in for this account. Reconnect it in the account settings.', 401, $e);
        } catch (\Throwable $e) {
            throw new CalendarSyncException('Microsoft could not renew the sign-in for this account.', 401, $e);
        }
    }

    private function translate(ResponseInterface $response, int $status, string $what): CalendarSyncException
    {
        [$code, $message] = $this->errorOf($response);
        $said             = '' === $code ? ('' === $message ? 'no reason given' : $message) : ('' === $message ? $code : $code . ': ' . $message);

        if (410 === $status || 'syncstatenotfound' === strtolower($code) || 'resyncrequired' === strtolower($code)) {
            return new CalendarResyncRequiredException('Microsoft Graph can no longer continue from the stored sync position, so the contacts are being read again from scratch.', 410);
        }

        if (412 === $status) {
            return new CalendarResyncRequiredException('The contact changed in Outlook since plMail last read it, so the edit was not applied.', 412);
        }

        if (429 === $status || 503 === $status) {
            return new CalendarSyncThrottledException(sprintf('Microsoft Graph is rate-limiting this account while %s.', $what), $status, $this->retryAfter($response));
        }

        if (401 === $status || (403 === $status && (in_array(strtolower($code), self::AUTHORIZATION_CODES, true) || str_contains(strtolower($message), 'access is denied')))) {
            return new CalendarSyncPermanentException(self::RECONNECT_HINT, $status);
        }

        if (400 === $status || 403 === $status || 404 === $status) {
            return new CalendarSyncPermanentException(sprintf('Microsoft Graph refused this while %s: %s', $what, $said), $status);
        }

        return new CalendarSyncException(sprintf('Microsoft Graph failed while %s (%d): %s', $what, $status, $said), $status);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function errorOf(ResponseInterface $response): array
    {
        try {
            $body = $response->getContent(false);
        } catch (HttpException) {
            return ['', ''];
        }

        $decoded = json_decode($body, true);
        $error   = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        if (false === is_array($error)) {
            return ['', mb_substr(trim($body), 0, 200)];
        }

        return [trim((string) ($error['code'] ?? '')), mb_substr(trim((string) ($error['message'] ?? '')), 0, 200)];
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
