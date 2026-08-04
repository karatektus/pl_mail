<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\CalDav;

use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Entity\Integration\Integration;
use Psr\Log\LoggerInterface;

/**
 * Finding the calendars behind an address a person typed.
 *
 * CalDAV's own bootstrap, RFC 6764: ask `/.well-known/caldav` on the domain,
 * follow where it points, PROPFIND for `current-user-principal`, PROPFIND that
 * for `calendar-home-set`, and list the home. Three round trips before a single
 * calendar has been seen, which is why it is a service of its own rather than
 * six lines inside discover() — the connect form calls it to tell a user
 * whether their address works at all, and it is the one part of this driver a
 * person will meet directly when it fails.
 *
 * A service rather than a method for a second reason: **what people paste is
 * usually not a domain.** Every other client shows a "CalDAV URL" somewhere,
 * and it is variously the server root, the principal, the calendar home, or one
 * calendar. All four arrive here, so the pasted URL is tried *first*, exactly as
 * given, and the well-known dance only runs when it taught us nothing. Trying
 * well-known first would mean a correct, specific URL being replaced by
 * whatever the server's front page redirects to — which on shared hosting is
 * somebody's marketing site.
 *
 * Failure is a sentence with the address in it, and permanent. There is no
 * point retrying a URL with no calendar service behind it, and the only thing
 * that fixes it is a person changing the address — so the message says what to
 * change it to.
 *
 * Every URL is validated before it is requested (CalDavClient::request), and
 * that matters more here than anywhere else in the driver: this is the one
 * place that follows a redirect, and a redirect is a server-controlled URL.
 * Following one into http://169.254.169.254 is the whole SSRF attack, so the
 * hop is revalidated rather than trusted for having come from a host that was.
 */
final readonly class CalDavDiscovery
{
    /** RFC 6764 §6: the well-known URI a CalDAV service is advertised at. */
    private const string WELL_KNOWN = '/.well-known/caldav';

    /**
     * How many redirects the bootstrap follows.
     *
     * Two is what the specified path costs (well-known → context path →
     * possibly a trailing-slash normalisation). More than that is a server
     * bouncing us around, and each hop is an unvalidated URL turned into a
     * request, so the loop is bounded rather than trusted.
     */
    private const int MAX_HOPS = 3;

    /**
     * What every probe asks for. resourcetype answers "is this itself a
     * calendar", and the other two are the two rungs of the bootstrap — asked
     * together because a server that can answer both saves a round trip, and
     * most can.
     */
    private const string PROBE_BODY = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
          <d:prop>
            <d:resourcetype/>
            <d:displayname/>
            <d:current-user-principal/>
            <cal:calendar-home-set/>
          </d:prop>
        </d:propfind>
        XML;

    /**
     * Statuses that mean "there is nothing here", as opposed to "you may not".
     *
     * 403 is in the list although the classifier calls it permanent elsewhere,
     * and only here: plenty of servers refuse a PROPFIND on the web root while
     * serving CalDAV perfectly at /dav, so a 403 at one step is a reason to try
     * the next step rather than to tell the user their password is wrong. A 401
     * is deliberately absent — that one really is the credentials, and it must
     * surface as itself instead of being reported three steps later as "no
     * calendar service found".
     */
    private const array NOT_HERE = [403, 404, 405, 501];

    public function __construct(
        private CalDavClient      $client,
        private MultiStatusParser $parser,
        private LoggerInterface   $logger,
    ) {
    }

    /**
     * Where this connection's calendars live.
     *
     * @throws CalendarSyncException
     */
    public function endpointFor(Integration $integration): CalDavEndpoint
    {
        $base = $this->client->baseUrl($integration);

        // The address as given, first — see the class docblock. Skipped when it
        // is a bare origin, because probing "https://example.com/" and then
        // probing its .well-known is the same server twice for the first half.
        if (true === $this->hasPath($base)) {
            $endpoint = $this->probe($integration, $base);

            if (null !== $endpoint) {
                return $endpoint;
            }
        }

        $endpoint = $this->probe($integration, $this->client->origin($base) . self::WELL_KNOWN);

        if (null !== $endpoint) {
            return $endpoint;
        }

        // Last: the origin itself. A server that neither serves .well-known nor
        // was given a usable path can still answer the bootstrap at its root,
        // and this is one request rather than a support ticket.
        $endpoint = $this->probe($integration, $this->client->origin($base));

        if (null !== $endpoint) {
            return $endpoint;
        }

        throw new CalendarSyncPermanentException(sprintf(
            'No calendar service answered at %s. Enter the CalDAV address your server shows you — often something like %s/remote.php/dav — rather than the address of its web interface.',
            $base,
            $this->client->origin($base),
        ));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * One PROPFIND, and what it settles.
     *
     * Null means "nothing here, try the next address"; anything else is an
     * answer. A 401 is not tolerated and so leaves through the classifier as
     * the credentials failure it is.
     *
     * @throws CalendarSyncException
     */
    private function probe(Integration $integration, string $url, int $hops = 0): ?CalDavEndpoint
    {
        if ($hops > self::MAX_HOPS) {
            return null;
        }

        $response = $this->client->request($integration, 'PROPFIND', $url, [
            'headers' => [
                'Depth'        => '0',
                'Content-Type' => 'application/xml; charset=utf-8',
            ],
            'body' => self::PROBE_BODY,
        ], [301, 302, 303, 307, 308, ...self::NOT_HERE]);

        $redirect = $this->client->redirectTarget($response, $url);

        if (null !== $redirect) {
            return $this->probe($integration, $redirect, $hops + 1);
        }

        if (true === in_array($response['status'], self::NOT_HERE, true)) {
            return null;
        }

        return $this->readEndpoint($integration, $response['body'], $url);
    }

    /**
     * @throws CalendarSyncException
     */
    private function readEndpoint(Integration $integration, string $body, string $url): ?CalDavEndpoint
    {
        foreach ($this->parser->parse($body) as $resource) {
            // A pasted calendar URL. Answered before the home, because a
            // calendar collection inside somebody's home also carries a
            // calendar-home-set on several servers — and the user pointed at
            // this calendar, not at everything they own.
            if (true === $resource->isCalendarCollection()) {
                return CalDavEndpoint::collection($this->client->absolutise($resource->href, $url));
            }

            $home = $resource->href('calendar-home-set');

            if (null !== $home) {
                return CalDavEndpoint::home(
                    $this->client->absolutise($home, $url),
                    $this->absolutePrincipal($resource, $url),
                );
            }
        }

        // Only then the extra round trip: the principal knows the home, and on
        // a correctly bootstrapped server that is exactly one more PROPFIND.
        foreach ($this->parser->parse($body) as $resource) {
            $principal = $this->absolutePrincipal($resource, $url);

            if (null === $principal || $principal === $url) {
                continue;
            }

            $home = $this->homeOf($integration, $principal);

            if (null !== $home) {
                return CalDavEndpoint::home($home, $principal);
            }
        }

        $this->logger->info('CalDav: no principal or calendar home in the response', ['url' => $url]);

        return null;
    }

    /**
     * The calendar-home-set on a principal.
     *
     * @throws CalendarSyncException
     */
    private function homeOf(Integration $integration, string $principal): ?string
    {
        $response = $this->client->request($integration, 'PROPFIND', $principal, [
            'headers' => [
                'Depth'        => '0',
                'Content-Type' => 'application/xml; charset=utf-8',
            ],
            'body' => self::PROBE_BODY,
        ], self::NOT_HERE);

        if (true === in_array($response['status'], self::NOT_HERE, true)) {
            return null;
        }

        foreach ($this->parser->parse($response['body']) as $resource) {
            $home = $resource->href('calendar-home-set');

            if (null !== $home) {
                return $this->client->absolutise($home, $principal);
            }
        }

        return null;
    }

    private function absolutePrincipal(DavResource $resource, string $url): ?string
    {
        $principal = $resource->href('current-user-principal');

        return null === $principal ? null : $this->client->absolutise($principal, $url);
    }

    /** Whether the address names something more specific than a host. */
    private function hasPath(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && '' !== trim($path, '/');
    }
}
