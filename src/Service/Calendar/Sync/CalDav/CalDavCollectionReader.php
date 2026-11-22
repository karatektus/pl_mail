<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\CalDav;

use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Entity\Integration\Integration;
use Psr\Log\LoggerInterface;

/**
 * Reading what changed in one CalDAV collection, whatever the collection holds.
 *
 * Moved out of CalDavCalendarDriver when tasks arrived, because a VTODO is read
 * exactly as a VEVENT is and two copies of this would drift the moment one of them
 * learned about a server's next oddity. What differs between the two is only the
 * component a listing filters on and what each driver makes of the iCalendar it
 * gets back, so it hands back resources and leaves that to the caller.
 *
 * The reasoning behind the two ways of reading is in CalDavCalendarDriver's
 * docblock, and applies to both.
 *
 * **A sync-collection report answers with everything in the collection.** It takes
 * no component filter, so on a collection holding both, the calendar's driver sees
 * VTODOs and the tasks' driver sees VEVENTs. Each converter declines what is not
 * its own, and a tombstone for a resource the other side owns matches no row, so
 * the two keep separate tokens on one collection without noticing each other.
 */
final readonly class CalDavCollectionReader
{
    /**
     * Everything worth knowing about a collection, asked in one PROPFIND.
     *
     * Also used at Depth: 0 before a pull, because the two questions the pull
     * has to answer first — can this server do sync-collection, and has
     * anything changed — are two of these properties.
     */
    private const string COLLECTION_PROPS = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"
                    xmlns:ical="http://apple.com/ns/ical/" xmlns:cs="http://calendarserver.org/ns/">
          <d:prop>
            <d:resourcetype/>
            <d:displayname/>
            <d:current-user-privilege-set/>
            <d:supported-report-set/>
            <d:sync-token/>
            <cs:getctag/>
            <ical:calendar-color/>
            <cal:calendar-timezone/>
            <cal:supported-calendar-component-set/>
          </d:prop>
        </d:propfind>
        XML;

    /**
     * How many sync-collection pages one pull will follow.
     *
     * A server that truncates answers with a 507 on the collection's own href
     * and a token to resume from (RFC 6578 §3.6), and the loop is what turns
     * that into the one complete window the contract promises. Bounded because
     * a server that always answers "truncated" with the same token would
     * otherwise be an infinite loop inside a Messenger handler — twenty pages
     * of a calendar is a big calendar, and stopping means the next poll
     * continues from the last token rather than losing anything.
     */
    private const int MAX_SYNC_PAGES = 20;

    public function __construct(
        private CalDavClient      $client,
        private MultiStatusParser $parser,
        private LoggerInterface   $logger,
    ) {}

    /**
     * Every collection the endpoint offers, described.
     *
     * Depth 1 on a home lists its children; Depth 0 on a single pasted collection
     * asks about that collection alone. Same body, same parse.
     *
     * @return list<DavResource>
     *
     * @throws CalendarSyncException
     */
    public function listCollections(Integration $integration, CalDavEndpoint $endpoint): array
    {
        $response = $this->client->request($integration, 'PROPFIND', $endpoint->collection ?? (string) $endpoint->home, [
            'headers' => [
                'Depth'        => true === $endpoint->isSingleCollection() ? '0' : '1',
                'Content-Type' => 'application/xml; charset=utf-8',
            ],
            'body' => self::COLLECTION_PROPS,
        ]);

        return $this->parser->parse($response['body']);
    }

    /**
     * What changed since $syncToken.
     *
     * @param string $component what a full listing filters on: VEVENT or VTODO
     *
     * @throws CalendarSyncException
     */
    public function read(Integration $integration, string $collection, ?string $syncToken, string $component): DavCollectionRead
    {
        $probe = $this->probeCollection($integration, $collection);

        if (true === $probe->contains('supported-report-set', 'sync-collection')) {
            return $this->readBySyncCollection($integration, $collection, $syncToken);
        }

        return $this->readByCtag($integration, $collection, $probe->prop('getctag'), $syncToken, $component);
    }

    // ── Pulling ───────────────────────────────────────────────────────────────

    /**
     * @throws CalendarSyncException
     */
    private function readBySyncCollection(
        Integration $integration,
        string      $collection,
        ?string     $syncToken,
    ): DavCollectionRead {
        $changes = [];
        $token   = $syncToken;

        for ($page = 0; $page < self::MAX_SYNC_PAGES; ++$page) {
            $response = $this->client->request($integration, 'REPORT', $collection, [
                'headers' => [
                    'Depth'        => '1',
                    'Content-Type' => 'application/xml; charset=utf-8',
                ],
                'body' => $this->syncReportBody($token),
            ]);

            $resources = $this->parser->parse($response['body']);
            $nextToken = $this->parser->syncToken($response['body']);

            foreach ($this->toChanges($integration, $collection, $resources) as $change) {
                $changes[] = $change;
            }

            // The token has to move for the next page to be a different one. A
            // server that truncates without issuing one has given us no way to
            // continue, and asking again would replay the same page forever.
            if (false === $this->isTruncated($resources) || null === $nextToken || $nextToken === $token) {
                return new DavCollectionRead($changes, $nextToken ?? $token);
            }

            $token = $nextToken;
        }

        $this->logger->warning('CalDav: stopped following a truncated sync report at the page cap', [
            'collection' => $collection,
            'pages'      => self::MAX_SYNC_PAGES,
        ]);

        return new DavCollectionRead($changes, $token);
    }

    /**
     * The fallback, and the reason a moved ctag surrenders the token rather
     * than reporting the listing — see CalDavCalendarDriver's docblock.
     *
     * A server with neither sync-collection nor a ctag lands here with a null
     * ctag, which never equals a stored token: it therefore reads the whole
     * collection on every poll. That is the only correct answer when the server
     * offers no way to ask whether anything changed, and it is why the ctag is
     * worth asking for even though it is not a standard.
     *
     * @throws CalendarSyncException
     */
    private function readByCtag(
        Integration $integration,
        string      $collection,
        ?string     $ctag,
        ?string     $syncToken,
        string      $component,
    ): DavCollectionRead {
        if (null !== $syncToken) {
            if (null !== $ctag && $ctag === $syncToken) {
                return new DavCollectionRead([], $syncToken);
            }

            return DavCollectionRead::resyncRequired();
        }

        $response = $this->client->request($integration, 'REPORT', $collection, [
            'headers' => [
                'Depth'        => '1',
                'Content-Type' => 'application/xml; charset=utf-8',
            ],
            'body' => $this->queryReportBody($component),
        ]);

        $changes = $this->toChanges($integration, $collection, $this->parser->parse($response['body']));

        // The ctag read *before* the listing, not after: a change landing
        // between the two would otherwise be marked as already seen and never
        // read. Stale in the other direction costs one redundant full read.
        return new DavCollectionRead($changes, $ctag);
    }

    /**
     * Multistatus responses turned into changes, fetching any bodies the report
     * left out.
     *
     * sync-collection is allowed to answer with etags alone, and several
     * servers do exactly that for large windows. The missing bodies are
     * collected and asked for in one calendar-multiget rather than one GET
     * each, because a window of two hundred changes would otherwise be two
     * hundred round trips.
     *
     * @param list<DavResource> $resources
     *
     * @return list<DavChange>
     *
     * @throws CalendarSyncException
     */
    private function toChanges(Integration $integration, string $collection, array $resources): array
    {
        $changes = [];
        $pending = [];

        foreach ($resources as $resource) {
            $href = $this->client->absolutise($resource->href, $collection);

            // The collection reports on itself in a sync report — it is the
            // href the truncation status is attached to — and it is not a
            // resource of its own.
            if (rtrim($href, '/') === rtrim($collection, '/')) {
                continue;
            }

            if (404 === $resource->status || 410 === $resource->status) {
                $changes[] = new DavChange($href, null, null);

                continue;
            }

            $data = $resource->prop('calendar-data');

            if (null === $data) {
                $pending[$href] = $resource->prop('getetag');

                continue;
            }

            $changes[] = new DavChange($href, $resource->prop('getetag'), $data);
        }

        foreach ($this->multiget($integration, $collection, array_keys($pending)) as $change) {
            $changes[] = $change;
        }

        return $changes;
    }

    /**
     * @param list<string> $hrefs
     *
     * @return list<DavChange>
     *
     * @throws CalendarSyncException
     */
    private function multiget(Integration $integration, string $collection, array $hrefs): array
    {
        if ([] === $hrefs) {
            return [];
        }

        $response = $this->client->request($integration, 'REPORT', $collection, [
            'headers' => [
                'Depth'        => '1',
                'Content-Type' => 'application/xml; charset=utf-8',
            ],
            'body' => $this->multigetBody($hrefs),
        ]);

        $changes = [];

        foreach ($this->parser->parse($response['body']) as $resource) {
            $data = $resource->prop('calendar-data');

            if (null === $data) {
                continue;
            }

            $changes[] = new DavChange(
                $this->client->absolutise($resource->href, $collection),
                $resource->prop('getetag'),
                $data,
            );
        }

        return $changes;
    }

    /**
     * RFC 6578 §3.6: a truncated report says so with a 507 on a response inside
     * the multistatus, which is a different thing from the HTTP 507 that means
     * the server will not produce the report at all — that one is classified as
     * a resync in CalDavClient.
     *
     * @param list<DavResource> $resources
     */
    private function isTruncated(array $resources): bool
    {
        foreach ($resources as $resource) {
            if (507 === $resource->status) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws CalendarSyncException
     */
    private function probeCollection(Integration $integration, string $collection): DavResource
    {
        $response = $this->client->request($integration, 'PROPFIND', $collection, [
            'headers' => [
                'Depth'        => '0',
                'Content-Type' => 'application/xml; charset=utf-8',
            ],
            'body' => self::COLLECTION_PROPS,
        ]);

        $resources = $this->parser->parse($response['body']);

        if ([] === $resources) {
            throw new CalendarSyncPermanentException(
                'The calendar server did not describe this calendar. It may have been deleted or renamed there.',
            );
        }

        return $resources[0];
    }

    // ── Request bodies ────────────────────────────────────────────────────────

    private function syncReportBody(?string $token): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:sync-collection xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">'
            . '<d:sync-token>%s</d:sync-token>'
            . '<d:sync-level>1</d:sync-level>'
            . '<d:prop><d:getetag/><cal:calendar-data/></d:prop>'
            . '</d:sync-collection>',
            // Empty for an initial sync, which is how RFC 6578 spells "send me
            // everything". Escaped because the token is the server's own string
            // and several servers put a URL with an ampersand in it.
            htmlspecialchars($token ?? '', ENT_XML1),
        );
    }

    /**
     * Every component of one kind in the collection, with no time-range filter.
     *
     * Unbounded on purpose. A range would make the listing cheaper and would
     * also make it a lie: the engine treats a full read as authoritative and
     * removes local rows the listing did not mention, so filtering to "the next
     * six months" would delete everything outside the window on the first
     * fallback pull.
     */
    private function queryReportBody(string $component): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="utf-8"?>'
            . '<cal:calendar-query xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">'
            . '<d:prop><d:getetag/><cal:calendar-data/></d:prop>'
            . '<cal:filter><cal:comp-filter name="VCALENDAR">'
            . '<cal:comp-filter name="%s"/>'
            . '</cal:comp-filter></cal:filter>'
            . '</cal:calendar-query>',
            htmlspecialchars($component, ENT_XML1),
        );
    }

    /**
     * @param list<string> $hrefs
     */
    private function multigetBody(array $hrefs): string
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<cal:calendar-multiget xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">'
            . '<d:prop><d:getetag/><cal:calendar-data/></d:prop>';

        foreach ($hrefs as $href) {
            $body .= sprintf('<d:href>%s</d:href>', htmlspecialchars($href, ENT_XML1));
        }

        return $body . '</cal:calendar-multiget>';
    }
}
