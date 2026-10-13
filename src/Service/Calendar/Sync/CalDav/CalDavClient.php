<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\CalDav;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\CalendarSyncThrottledException;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Service\Integration\IntegrationUrlValidator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Every HTTP request this driver makes, and every failure it turns into a
 * CalendarSyncException.
 *
 * CardDAV goes through here too. It is the same transport — PROPFIND, REPORT,
 * PUT with If-Match — to the same kind of server, usually the same server, and
 * both security decisions below are exactly as necessary for an address book.
 * Only the wording of the failures differs, and say() picks it.
 *
 * One class rather than a `request()` at the bottom of the driver, because
 * three callers need it — discovery, the driver and anything added later — and
 * because two of the things it does are security decisions that must not have
 * a second implementation:
 *
 *   **No URL is requested that IntegrationUrlValidator has not seen.** The base
 *   address is the user's, the hrefs inside a multistatus are the server's, and
 *   both end up in an outbound request from a container that can reach
 *   Postgres, Mercure and the workers. Validating in one choke point is what
 *   makes "the SSRF check is not optional" a property of the code rather than a
 *   rule every method has to remember.
 *
 *   **Redirects are never followed automatically.** Symfony's client turns a
 *   302 on a PROPFIND into a GET on the target, which answers 200 with an HTML
 *   page — so the parser sees a login screen where it expected a multistatus
 *   and reports "not a CalDAV response" about a server that is fine. Worse, an
 *   automatic follow would jump to a Location nobody validated. max_redirects
 *   is 0 everywhere and CalDavDiscovery follows the one redirect CalDAV
 *   actually specifies (RFC 6764's .well-known) by hand, revalidating as it
 *   goes.
 *
 * Classification is the other half. Which subclass a status becomes decides
 * whether Messenger retries, gives up, or reads the calendar from scratch, and
 * two of the mappings are not obvious:
 *
 *   A 403 is *usually* permanent and occasionally a dead sync token. Servers
 *   answer the `valid-sync-token` precondition with 403 (RFC 6578 §3.2 says
 *   403 or 409, and implementations use both plus 400), so the body is read
 *   for the precondition before the status is trusted.
 *
 *   A 507 is a resync rather than the "out of space" it means over plain
 *   WebDAV. It is what a server answers when the sync report it was asked for
 *   is more than it will produce at all — as opposed to the in-body 507 on the
 *   collection href, which means "here is a page, come back with this token"
 *   and is handled as paging in the driver.
 */
final readonly class CalDavClient
{
    /**
     * Every request carries this, and it is not decoration: several servers
     * (Radicale among them) refuse or mis-handle requests from a client that
     * does not name itself, and a support thread about a failing sync starts
     * with the server's access log.
     */
    private const string USER_AGENT = 'plMail-CalDAV/1.0';

    /** Statuses that carry a Location and mean "ask over there instead". */
    private const array REDIRECTS = [301, 302, 303, 307, 308];

    public function __construct(
        private HttpClientInterface                 $httpClient,
        private IntegrationUrlValidator             $urlValidator,
        private IntegrationProviderConfigRepository $configs,
        private MultiStatusParser                   $parser,
        private LoggerInterface                     $logger = new NullLogger(),
    ) {
    }

    /**
     * The connection's server address, admin pin winning, SSRF-checked.
     *
     * The pin is looked up for the connection's own provider rather than for
     * CalDAV by name: this client also carries CardDAV, and an address an
     * administrator pinned for calendars says nothing about where contacts
     * live.
     *
     * The IntegrationException the validator raises is already phrased for a
     * person — "Server address must not point at a private network" — so it is
     * carried across into the calendar hierarchy verbatim rather than being
     * replaced by a vaguer sentence. Permanent, because no amount of retrying
     * makes a private address public.
     *
     * @throws CalendarSyncPermanentException
     */
    public function baseUrl(Integration $integration): string
    {
        try {
            return $this->urlValidator->resolve($integration, $this->configs->findOneByProvider($integration->provider));
        } catch (IntegrationException $e) {
            throw new CalendarSyncPermanentException($e->getMessage(), $e->getStatus(), $e);
        }
    }

    /**
     * One CalDAV request, answered with its status, body and headers.
     *
     * Returns rather than throws for anything in $tolerate, because three
     * callers need a status the classifier would otherwise have written off:
     * discovery treats a 404 on .well-known as "try somewhere else", delete()
     * treats a 404 as success, and discovery reads the Location off a 3xx.
     *
     * @param array<string,mixed> $options passed to the HTTP client as-is
     * @param list<int>           $tolerate statuses the caller handles itself
     *
     * @return array{status:int,body:string,headers:array<string,list<string>>}
     *
     * @throws CalendarSyncException
     */
    public function request(
        Integration $integration,
        string      $method,
        string      $url,
        array       $options = [],
        array       $tolerate = [],
    ): array {
        $this->assertReachable($url);

        if (null === $integration->username || null === $integration->secret) {
            throw new CalendarSyncPermanentException($this->say(
                $integration->provider,
                'This calendar connection is missing its username or app password. Open it in settings and enter them again.',
                'This contacts connection is missing its username or app password. Open it in settings and enter them again.',
            ));
        }

        $headers = $options['headers'] ?? [];

        $options['auth_basic']    = [$integration->username, $integration->secret];
        $options['max_redirects'] = 0;
        $options['headers']       = array_merge(
            ['User-Agent' => self::USER_AGENT],
            true === is_array($headers) ? $headers : [],
        );

        try {
            $response = $this->httpClient->request($method, $url, $options);

            // Read with `false` throughout: a 4xx must reach the classifier
            // below with its body intact, and Symfony's own exceptions would
            // otherwise fire on getStatusCode() before the body — which is
            // where the server says whether the sync token is the problem.
            $status  = $response->getStatusCode();
            $body    = $response->getContent(false);
            $headers = $response->getHeaders(false);
        } catch (HttpExceptionInterface $e) {
            // DNS failure, refused connection, TLS mismatch, timeout. Not
            // classified: a server that is down comes back, and writing it off
            // permanently would need a person to reconnect a calendar that
            // healed itself.
            throw new CalendarSyncException(
                $this->say(
                    $integration->provider,
                    'Could not reach the calendar server. Check the server address and that it is online.',
                    'Could not reach the contacts server. Check the server address and that it is online.',
                ),
                0,
                $e,
            );
        }

        if (($status >= 200 && $status <= 299) || true === in_array($status, $tolerate, true)) {
            return ['status' => $status, 'body' => $body, 'headers' => $headers];
        }

        throw $this->classify($status, $body, $headers, $integration->provider);
    }

    /**
     * Where a 3xx points, as an absolute URL, or null when the response is not
     * a redirect or names no target.
     *
     * @param array{status:int,body:string,headers:array<string,list<string>>} $response
     */
    public function redirectTarget(array $response, string $requestUrl): ?string
    {
        if (false === in_array($response['status'], self::REDIRECTS, true)) {
            return null;
        }

        $location = $response['headers']['location'][0] ?? '';

        return '' === $location ? null : $this->absolutise($location, $requestUrl);
    }

    /**
     * An href from a multistatus body, made absolute against the URL that was
     * asked.
     *
     * Servers answer with all three legal forms — a bare path, a full URL, and
     * occasionally a relative one — and the driver stores what comes out of
     * here in Calendar::$remoteId and CalendarEvent::$remoteId. Absolute, so
     * that an id keeps meaning the same resource after the connection's base
     * address is edited, and so that a discovery that redirected onto another
     * host (which is exactly what RFC 6764 bootstrapping does) is not silently
     * re-pointed at the original one.
     */
    public function absolutise(string $href, string $context): string
    {
        $href = trim($href);

        if (1 === preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $parts  = parse_url($context);
        $scheme = is_array($parts) ? (string) ($parts['scheme'] ?? 'https') : 'https';
        $host   = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $port   = is_array($parts) && true === isset($parts['port']) ? ':' . $parts['port'] : '';
        $origin = sprintf('%s://%s%s', $scheme, $host, $port);

        if (true === str_starts_with($href, '/')) {
            return $origin . $href;
        }

        $path = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';

        return $origin . rtrim(substr($path, 0, (int) strrpos($path, '/') + 1), '/') . '/' . $href;
    }

    /**
     * The scheme, host and port of a URL, with no path and no trailing slash —
     * what .well-known and the fallback probe are appended to.
     */
    public function origin(string $url): string
    {
        return rtrim($this->absolutise('/', $url), '/');
    }

    /** @throws CalendarSyncPermanentException */
    public function assertReachable(string $url): void
    {
        try {
            $this->urlValidator->assertAllowed($url);
        } catch (IntegrationException $e) {
            throw new CalendarSyncPermanentException($e->getMessage(), $e->getStatus(), $e);
        }
    }

    /**
     * The etag the server assigned, asking for it again when the PUT did not
     * say.
     *
     * Servers are not required to answer a PUT with an ETag, and a good many do
     * not — RFC 4791 §5.3.4 and RFC 6352 §6.3.2.3 anticipate exactly this and
     * say the client may GET the resource to learn it. Without that follow-up
     * the local row stores a null etag, which makes the next pull treat the
     * event or card as changed and write it again: correct, but a wasted write
     * per push forever.
     *
     * Here rather than in each driver because it is the same request and the
     * same tolerance for a calendar event and a vCard.
     *
     * A failure to read it back is not a failure of the push, which has already
     * succeeded — the null is stored and the next pull repairs it.
     *
     * @param array<string,list<string>> $headers
     */
    public function etagAfterWrite(Integration $integration, string $href, array $headers): ?string
    {
        $etag = trim($headers['etag'][0] ?? '');

        if ('' !== $etag) {
            return $etag;
        }

        try {
            $response = $this->request($integration, 'GET', $href, [], [404, 410]);
        } catch (CalendarSyncException $e) {
            $this->logger->info('CalDav: could not read back the etag after a write', [
                'provider'  => $integration->provider->value,
                'href'      => $href,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            return null;
        }

        $etag = trim($response['headers']['etag'][0] ?? '');

        return '' === $etag ? null : $etag;
    }

    /**
     * @param array<string,list<string>> $headers
     * @param Provider|null              $provider whose wording to use — the
     *                                             exception classes are the
     *                                             same for an address book,
     *                                             the sentence is not
     */
    public function classify(int $status, string $body, array $headers = [], ?Provider $provider = null): CalendarSyncException
    {
        // Before the status, because the same precondition arrives as 403, 409
        // and 400 depending on whose server it is — and a dead token
        // classified as "the password was rejected" tells the user to fix
        // something that is not broken.
        if (true === $this->parser->hasPrecondition($body, 'valid-sync-token')) {
            return new CalendarResyncRequiredException(
                $this->say(
                    $provider,
                    'The calendar server no longer recognises our sync position, so the calendar is being read again from scratch.',
                    'The contacts server no longer recognises our sync position, so the address book is being read again from scratch.',
                ),
                $status,
            );
        }

        return match (true) {
            401 === $status => new CalendarSyncPermanentException(
                $this->say(
                    $provider,
                    'The calendar server rejected the app password. Generate a new one and enter it in the connection settings.',
                    'The contacts server rejected the app password. Generate a new one and enter it in the connection settings.',
                ),
                $status,
            ),
            403 === $status => new CalendarSyncPermanentException(
                $this->say(
                    $provider,
                    'The calendar server refused access to this calendar. Check that the app password still has calendar permission.',
                    'The contacts server refused access to this address book. Check that the app password still has contacts permission.',
                ),
                $status,
            ),
            404 === $status || 410 === $status => new CalendarSyncPermanentException(
                $this->say(
                    $provider,
                    'This calendar no longer exists on the server. Unsubscribe from it, or subscribe again if it has moved.',
                    'This address book no longer exists on the server. Unsubscribe from it, or subscribe again if it has moved.',
                ),
                $status,
            ),
            429 === $status || 503 === $status => new CalendarSyncThrottledException(
                $this->say($provider, 'The calendar server asked us to slow down.', 'The contacts server asked us to slow down.'),
                $status,
                $this->retryAfter($headers),
            ),
            507 === $status => new CalendarResyncRequiredException(
                $this->say(
                    $provider,
                    'The calendar server would not report the changes since our last sync, so the calendar is being read again from scratch.',
                    'The contacts server would not report the changes since our last sync, so the address book is being read again from scratch.',
                ),
                $status,
            ),
            // Everything else, 5xx included, stays unclassified on purpose: the
            // hierarchy's rule is that a permanent classification is a decision
            // never to try again, and a 500 from a CalDAV server is far more
            // often a restart than a refusal.
            default => new CalendarSyncException(
                sprintf($this->say(
                    $provider,
                    'The calendar server returned an unexpected response (%d).',
                    'The contacts server returned an unexpected response (%d).',
                ), $status),
                $status,
            ),
        };
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The calendar sentence or the contacts one.
     *
     * This client carries CardDAV as well as CalDAV, and the exception
     * hierarchy — permanent, throttled, resync — is the same for both because
     * the retry decisions are. What differs is what a person reads in the
     * settings list, and "the calendar server rejected the app password" under
     * an address book sends them looking at the wrong connection. Two literal
     * sentences rather than one with a placeholder, so each reads naturally and
     * a grep for either finds it.
     */
    private function say(?Provider $provider, string $calendar, string $contacts): string
    {
        return Provider::CardDav === $provider ? $contacts : $calendar;
    }

    /**
     * Seconds, and only when the header says seconds.
     *
     * Retry-After may also be an HTTP date, and parsing one against our clock
     * is how a delay becomes negative or a week long — the throttled
     * exception's own fallback is a better answer than a bad number.
     *
     * @param array<string,list<string>> $headers
     */
    private function retryAfter(array $headers): ?int
    {
        $value = trim($headers['retry-after'][0] ?? '');

        if (1 !== preg_match('/^\d+$/', $value)) {
            return null;
        }

        return (int) $value;
    }
}
