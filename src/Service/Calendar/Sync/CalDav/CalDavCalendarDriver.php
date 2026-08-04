<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\CalDav;

use App\Domain\DTO\Calendar\CalendarChangeSet;
use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Calendar\RemoteCalendar;
use App\Domain\DTO\Calendar\RemoteEvent;
use App\Domain\DTO\Calendar\RemoteWriteResult;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\IntegrationException;
use App\Domain\Interface\CalendarSyncDriverInterface;
use App\Domain\Interface\VerifiableDriverInterface;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Integration\Integration;
use Psr\Log\LoggerInterface;

/**
 * Calendars on any RFC 4791 server, over the protocol rather than an API.
 *
 * The one driver that talks to software nobody here chose: Nextcloud, Radicale,
 * Baïkal, Fastmail, iCloud, a Synology box in a cupboard. So every capability
 * is *asked for* rather than assumed — whether the collection accepts writes
 * comes from current-user-privilege-set, whether changes can be read
 * incrementally comes from supported-report-set — and there is no per-vendor
 * branch anywhere in this file. A server plMail has never heard of works the
 * day it is pointed at, and that is the whole design.
 *
 * ── The two ways to read changes ──────────────────────────────────────────
 *
 * **sync-collection (RFC 6578) when the server advertises it.** One REPORT
 * carrying the stored token answers with the events that changed and a
 * `<status>404</status>` for each one removed, which is exactly a
 * CalendarChangeSet. It is the only mechanism here that can express a deletion
 * incrementally, so it is preferred wherever it exists.
 *
 * **getctag plus calendar-query when it does not**, which is not a rare
 * fallback — Radicale, older Baïkal and several appliance servers advertise no
 * sync-collection at all, and a driver that only spoke RFC 6578 would report
 * "this calendar cannot be synced" for a working CalDAV server. The ctag is one
 * value for the whole collection: equal to what was stored means nothing has
 * changed anywhere, which is the answer to most polls and costs one PROPFIND.
 *
 * When the ctag *has* moved, this asks for a full resync rather than returning
 * the listing. That looks wasteful and is deliberate: a calendar-query answers
 * with everything that currently exists and says nothing about what was
 * deleted, and the engine only treats a listing as authoritative — removing
 * local rows it did not mention — when it asked with a null token. Returning
 * the listing against a live token would apply every edit and silently keep
 * every deleted event forever. So the token is surrendered, the engine re-pulls
 * from scratch, and the deletion lands. Two requests instead of one, once per
 * change, in exchange for a calendar that does not accumulate ghosts.
 *
 * Both mechanisms store their position in the same Calendar::$syncToken, and
 * the two spellings cannot be told apart. That is safe in both directions and
 * neither needed a flag: a ctag presented to a server that has since grown
 * sync-collection support comes back as the `valid-sync-token` precondition and
 * becomes a resync, and a sync-token compared against a ctag simply never
 * matches, which is also a resync. Both self-heal in one poll.
 *
 * ── Identity ──────────────────────────────────────────────────────────────
 *
 * Every remoteId here is an **absolute URL**, calendars and events alike. An
 * href is meaningful only against the server that issued it, RFC 6764
 * bootstrapping routinely lands on a different host than the one the user
 * typed, and a connection's base address can be edited afterwards — a stored
 * path would then silently start addressing the new host's calendars. The ids
 * are opaque to everything above this driver, so their being URLs costs
 * nothing and buys a calendar that keeps pointing where it was found.
 *
 * ── Failure ───────────────────────────────────────────────────────────────
 *
 * All of it is in CalDavClient::classify(), including the two decisions that
 * are not obvious from the status: a 403 may be a dead sync token rather than a
 * refusal, and a 507 means "read it again from scratch" rather than "the disk
 * is full". verify() is the exception — it is called by the connect and test
 * paths, which catch IntegrationException — so it translates at its own edge,
 * keeping the status so Integration::isAuthFailure() still answers.
 */
final readonly class CalDavCalendarDriver implements CalendarSyncDriverInterface, VerifiableDriverInterface
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

    /**
     * Privileges any one of which means "we may write here".
     *
     * write-content is the one that matters — it is what permits a PUT of an
     * event body — but a server that grants the coarser `write` or `all`
     * without enumerating the finer ones is not read-only, and treating it as
     * such would silently strand every edit a user makes.
     *
     * @var list<string>
     */
    private const array WRITE_PRIVILEGES = ['write-content', 'write', 'all'];

    public function __construct(
        private CalDavClient         $client,
        private CalDavDiscovery      $discovery,
        private MultiStatusParser    $parser,
        private CalDavEventConverter $converter,
        private LoggerInterface      $logger,
    ) {
    }

    /**
     * One method for two interfaces, which PHP permits because a parameter type
     * may be widened: `Provider|CalendarSource` is a supertype of each
     * interface's own parameter, so this satisfies both signatures.
     *
     * The alternative was a second class implementing VerifiableDriverInterface
     * and delegating here — a file whose only content is the fact that two
     * interfaces chose the same verb. The union says the same thing in one
     * place, and the match below has no default arm so a third caller with a
     * third argument type is a compile-time conversation rather than a silent
     * false.
     */
    public function supports(Provider|CalendarSource $subject): bool
    {
        return match (true) {
            $subject instanceof Provider       => Provider::CalDav === $subject,
            $subject instanceof CalendarSource => Provider::CalDav === $subject->integrationProvider(),
        };
    }

    /**
     * Prove the credentials by finding the calendars behind them.
     *
     * A PROPFIND rather than a GET, and the whole bootstrap rather than one
     * request: an address that authenticates but has no calendar service on it
     * is not a working connection, and discovering that at connect time — while
     * the user is looking at the form — is the difference between a sentence
     * they can act on and a calendar that silently never fills in.
     *
     * @throws IntegrationException
     */
    public function verify(Integration $integration): void
    {
        try {
            $this->discovery->endpointFor($integration);
        } catch (CalendarSyncException $e) {
            // The status is carried across so Integration::isAuthFailure()
            // still separates "your app password was rejected" from "the
            // server is down", which is what the connect form says next.
            throw new IntegrationException($e->getMessage(), $e->getStatus(), $e);
        }
    }

    /**
     * @return list<RemoteCalendar>
     *
     * @throws CalendarSyncException
     */
    public function discover(CalendarSource $source): array
    {
        $integration = $source->integration;

        if (null === $integration) {
            throw new CalendarSyncPermanentException(
                'This calendar source is not a CalDAV connection, so there is nothing to discover.',
            );
        }

        $endpoint = $this->discovery->endpointFor($integration);
        $url      = $endpoint->collection ?? (string) $endpoint->calendarHome;

        // Depth 1 on a home lists its children; Depth 0 on a single pasted
        // collection asks about that collection alone. Same body, same parse.
        $response = $this->client->request($integration, 'PROPFIND', $url, [
            'headers' => [
                'Depth'        => true === $endpoint->isSingleCollection() ? '0' : '1',
                'Content-Type' => 'application/xml; charset=utf-8',
            ],
            'body' => self::COLLECTION_PROPS,
        ]);

        $calendars = [];

        foreach ($this->parser->parse($response['body']) as $resource) {
            if (false === $resource->isCalendarCollection() || false === $this->holdsEvents($resource)) {
                continue;
            }

            $href = $this->client->absolutise($resource->href, $url);

            $calendars[] = new RemoteCalendar(
                remoteId:   $href,
                name:       $this->nameOf($resource, $href),
                color:      $this->colourOf($resource),
                timeZone:   $this->timeZoneOf($resource),
                isReadOnly: $this->isReadOnly($resource),
                // CalDAV has no notion of a primary calendar — no property says
                // it and no server implies it — so nothing is ticked by
                // default rather than one being guessed at.
                isPrimary:  false,
            );
        }

        return $calendars;
    }

    /**
     * @throws CalendarSyncException
     */
    public function pull(Calendar $calendar, ?string $syncToken): CalendarChangeSet
    {
        $integration = $this->integrationOf($calendar);
        $collection  = $this->collectionOf($calendar);

        $probe = $this->probeCollection($integration, $collection);

        if (true === $probe->contains('supported-report-set', 'sync-collection')) {
            return $this->pullBySyncCollection($integration, $collection, $syncToken);
        }

        return $this->pullByCtag($integration, $collection, $probe->prop('getctag'), $syncToken);
    }

    /**
     * @throws CalendarSyncException
     */
    public function push(Calendar $calendar, CalendarEvent $event): RemoteWriteResult
    {
        $integration = $this->integrationOf($calendar);
        $isCreate    = null === $event->remoteId;
        $href        = $event->remoteId ?? $this->mintHref($calendar, $event);

        $headers = ['Content-Type' => 'text/calendar; charset=utf-8'];

        if (true === $isCreate) {
            // The convention, and a real guard: a UID-derived href is
            // predictable, so two clients creating the same meeting pick the
            // same one. If-None-Match makes the second of them a 412 instead of
            // a silent overwrite of the first.
            $headers['If-None-Match'] = '*';
        } elseif (null !== $event->remoteEtag) {
            $headers['If-Match'] = $event->remoteEtag;
        }

        $response = $this->client->request($integration, 'PUT', $href, [
            'headers' => $headers,
            'body'    => $this->converter->toIcs($event),
        ], [412]);

        if (412 === $response['status']) {
            throw new CalendarResyncRequiredException(
                'The event changed on the calendar server before our copy could be saved, so the calendar is being read again.',
                412,
            );
        }

        return new RemoteWriteResult($href, $this->etagAfterWrite($integration, $href, $response['headers']));
    }

    /**
     * @throws CalendarSyncException
     */
    public function delete(Calendar $calendar, CalendarEvent $event): void
    {
        $href = $event->remoteId;

        if (null === $href) {
            // The engine already promises not to call this, so this is a guard
            // against a future caller rather than a case that happens: an event
            // the remote never saw needs no DELETE, and sending one to the
            // collection URL would delete the calendar.
            return;
        }

        $integration = $this->integrationOf($calendar);
        $headers     = null === $event->remoteEtag ? [] : ['If-Match' => $event->remoteEtag];

        // 404 and 410 are tolerated because deleting is idempotent by contract:
        // the job is to make the event not exist, and it does not. Treating the
        // second attempt as a failure leaves the row in PendingDelete forever,
        // re-sending the same DELETE on every sweep.
        $response = $this->client->request($integration, 'DELETE', $href, [
            'headers' => $headers,
        ], [404, 410, 412]);

        if (412 === $response['status']) {
            throw new CalendarResyncRequiredException(
                'The event changed on the calendar server before it could be deleted, so the calendar is being read again.',
                412,
            );
        }
    }

    // ── Pulling ───────────────────────────────────────────────────────────────

    /**
     * @throws CalendarSyncException
     */
    private function pullBySyncCollection(
        Integration $integration,
        string      $collection,
        ?string     $syncToken,
    ): CalendarChangeSet {
        $events = [];
        $token  = $syncToken;

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

            foreach ($this->toRemoteEvents($integration, $collection, $resources) as $event) {
                $events[] = $event;
            }

            // The token has to move for the next page to be a different one. A
            // server that truncates without issuing one has given us no way to
            // continue, and asking again would replay the same page forever.
            if (false === $this->isTruncated($resources) || null === $nextToken || $nextToken === $token) {
                return new CalendarChangeSet($events, $nextToken ?? $token);
            }

            $token = $nextToken;
        }

        $this->logger->warning('CalDav: stopped following a truncated sync report at the page cap', [
            'collection' => $collection,
            'pages'      => self::MAX_SYNC_PAGES,
        ]);

        return new CalendarChangeSet($events, $token);
    }

    /**
     * The fallback, and the reason a moved ctag surrenders the token rather
     * than reporting the listing — see the class docblock.
     *
     * A server with neither sync-collection nor a ctag lands here with a null
     * ctag, which never equals a stored token: it therefore reads the whole
     * calendar on every poll. That is the only correct answer when the server
     * offers no way to ask whether anything changed, and it is why the ctag is
     * worth asking for even though it is not a standard.
     *
     * @throws CalendarSyncException
     */
    private function pullByCtag(
        Integration $integration,
        string      $collection,
        ?string     $ctag,
        ?string     $syncToken,
    ): CalendarChangeSet {
        if (null !== $syncToken) {
            if (null !== $ctag && $ctag === $syncToken) {
                return CalendarChangeSet::unchanged($syncToken);
            }

            return CalendarChangeSet::resyncRequired();
        }

        $response = $this->client->request($integration, 'REPORT', $collection, [
            'headers' => [
                'Depth'        => '1',
                'Content-Type' => 'application/xml; charset=utf-8',
            ],
            'body' => $this->queryReportBody(),
        ]);

        $events = $this->toRemoteEvents(
            $integration,
            $collection,
            $this->parser->parse($response['body']),
        );

        // The ctag read *before* the listing, not after: a change landing
        // between the two would otherwise be marked as already seen and never
        // read. Stale in the other direction costs one redundant full read.
        return new CalendarChangeSet($events, $ctag);
    }

    /**
     * Multistatus responses turned into RemoteEvents, fetching any bodies the
     * report left out.
     *
     * sync-collection is allowed to answer with etags alone, and several
     * servers do exactly that for large windows. The missing bodies are
     * collected and asked for in one calendar-multiget rather than one GET
     * each, because a window of two hundred changes would otherwise be two
     * hundred round trips.
     *
     * @param list<DavResource> $resources
     *
     * @return list<RemoteEvent>
     *
     * @throws CalendarSyncException
     */
    private function toRemoteEvents(Integration $integration, string $collection, array $resources): array
    {
        $events  = [];
        $pending = [];

        foreach ($resources as $resource) {
            $href = $this->client->absolutise($resource->href, $collection);

            // The collection reports on itself in a sync report — it is the
            // href the truncation status is attached to — and it is not an
            // event.
            if (rtrim($href, '/') === rtrim($collection, '/')) {
                continue;
            }

            if (404 === $resource->status || 410 === $resource->status) {
                $events[] = RemoteEvent::deleted($href);

                continue;
            }

            $data = $resource->prop('calendar-data');

            if (null === $data) {
                $pending[$href] = $resource->prop('getetag');

                continue;
            }

            $event = $this->converter->toRemoteEvent($data, $href, $resource->prop('getetag'));

            if (null === $event) {
                $this->logger->info('CalDav: skipped a resource that is not a usable event', ['href' => $href]);

                continue;
            }

            $events[] = $event;
        }

        foreach ($this->multiget($integration, $collection, array_keys($pending)) as $event) {
            $events[] = $event;
        }

        return $events;
    }

    /**
     * @param list<string> $hrefs
     *
     * @return list<RemoteEvent>
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

        $events = [];

        foreach ($this->parser->parse($response['body']) as $resource) {
            $data = $resource->prop('calendar-data');

            if (null === $data) {
                continue;
            }

            $event = $this->converter->toRemoteEvent(
                $data,
                $this->client->absolutise($resource->href, $collection),
                $resource->prop('getetag'),
            );

            if (null !== $event) {
                $events[] = $event;
            }
        }

        return $events;
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
     * Every VEVENT in the collection, with no time-range filter.
     *
     * Unbounded on purpose. A range would make the listing cheaper and would
     * also make it a lie: the engine treats a full read as authoritative and
     * removes local rows the listing did not mention, so filtering to "the next
     * six months" would delete everything outside the window on the first
     * fallback pull.
     */
    private function queryReportBody(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<cal:calendar-query xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">'
            . '<d:prop><d:getetag/><cal:calendar-data/></d:prop>'
            . '<cal:filter><cal:comp-filter name="VCALENDAR">'
            . '<cal:comp-filter name="VEVENT"/>'
            . '</cal:comp-filter></cal:filter>'
            . '</cal:calendar-query>';
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

    // ── Reading one collection ────────────────────────────────────────────────

    /**
     * Whether the collection holds events at all.
     *
     * supported-calendar-component-set is how a server says a collection is for
     * tasks or journals only, and Apple-flavoured servers do publish
     * VTODO-only collections under the same home. An absent property means the
     * server does not restrict the collection, which RFC 4791 says to read as
     * "anything", so absence is a yes.
     */
    private function holdsEvents(DavResource $resource): bool
    {
        if (false === $resource->hasProp('supported-calendar-component-set')) {
            return true;
        }

        return $resource->names('supported-calendar-component-set', 'VEVENT');
    }

    private function nameOf(DavResource $resource, string $href): string
    {
        $name = $resource->prop('displayname');

        if (null !== $name) {
            return $name;
        }

        // A collection with no displayname is legal and not rare on Radicale.
        // The last path segment is what every other client shows for one, and
        // it is at least the address the user can recognise.
        $segment = basename(rtrim((string) parse_url($href, PHP_URL_PATH), '/'));

        return '' === $segment ? 'Calendar' : rawurldecode($segment);
    }

    /**
     * Apple's calendar-color, which is where every server that has a colour
     * puts one.
     *
     * The value is routinely **#rrggbbaa** — eight digits, the last two an
     * alpha channel Apple's clients use for the "inactive" shade. Calendar's
     * own column is seven characters, and passing the alpha through would fail
     * the column length on some servers and render as an unknown colour on the
     * rest, so it is dropped. Anything that is not six or eight hex digits is
     * refused outright rather than half-parsed: the provisioner then picks from
     * the palette, which is a colour a user can live with, where a mangled one
     * is a calendar that is invisible against the background.
     */
    private function colourOf(DavResource $resource): ?string
    {
        $raw = $resource->prop('calendar-color');

        if (null === $raw) {
            return null;
        }

        $hex = ltrim(trim($raw), '#');

        if (1 !== preg_match('/^[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $hex)) {
            return null;
        }

        return '#' . mb_strtolower(substr($hex, 0, 6));
    }

    private function timeZoneOf(DavResource $resource): ?string
    {
        $raw = $resource->prop('calendar-timezone');

        return null === $raw ? null : $this->converter->timeZoneOf($raw);
    }

    /**
     * Read-only when the server enumerated our privileges and none of them
     * permits a write.
     *
     * A collection whose privilege set is absent is treated as writable. The
     * property is a SHOULD, several servers omit it, and defaulting to
     * read-only there would make every calendar on such a server refuse edits
     * with no way for the user to tell why — where defaulting to writable
     * costs, at worst, a 403 on the first push, which is reported as itself.
     */
    private function isReadOnly(DavResource $resource): bool
    {
        if (false === $resource->hasProp('current-user-privilege-set')) {
            return false;
        }

        foreach (self::WRITE_PRIVILEGES as $privilege) {
            if (true === $resource->contains('current-user-privilege-set', $privilege)) {
                return false;
            }
        }

        return true;
    }

    // ── Plumbing ──────────────────────────────────────────────────────────────

    /**
     * The href a new event is created at.
     *
     * Minted from the UID, which is CalDAV's own convention (RFC 4791 §5.3.2
     * recommends it) and is what makes an event created here recognisable to
     * the invitation for the same meeting sitting in the mailbox. Encoded,
     * because a UID is somebody else's string and routinely contains a slash,
     * an at-sign or a space.
     */
    private function mintHref(Calendar $calendar, CalendarEvent $event): string
    {
        return sprintf('%s/%s.ics', rtrim($this->collectionOf($calendar), '/'), rawurlencode($event->uid));
    }

    /**
     * The etag the server assigned, asking for it again when the PUT did not
     * say.
     *
     * Servers are not required to answer a PUT with an ETag, and a good many do
     * not — RFC 4791 §5.3.4 anticipates exactly this and says the client may
     * GET the resource to learn it. Without that follow-up the local row stores
     * a null etag, which makes the next pull treat the event as changed and
     * write it again: correct, but a wasted write per push forever.
     *
     * A failure to read it back is not a failure of the push, which has already
     * succeeded — the null is stored and the next pull repairs it.
     *
     * @param array<string,list<string>> $headers
     */
    private function etagAfterWrite(Integration $integration, string $href, array $headers): ?string
    {
        $etag = trim($headers['etag'][0] ?? '');

        if ('' !== $etag) {
            return $etag;
        }

        try {
            $response = $this->client->request($integration, 'GET', $href, [], [404, 410]);
        } catch (CalendarSyncException $e) {
            $this->logger->info('CalDav: could not read back the etag after a write', [
                'href'  => $href,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $etag = trim($response['headers']['etag'][0] ?? '');

        return '' === $etag ? null : $etag;
    }

    /** @throws CalendarSyncPermanentException */
    private function integrationOf(Calendar $calendar): Integration
    {
        $integration = $calendar->integration;

        if (null === $integration) {
            throw new CalendarSyncPermanentException(
                'This calendar is not connected to a CalDAV server any more.',
            );
        }

        return $integration;
    }

    /** @throws CalendarSyncPermanentException */
    private function collectionOf(Calendar $calendar): string
    {
        $collection = $calendar->remoteId;

        if (null === $collection || '' === $collection) {
            throw new CalendarSyncPermanentException(
                'This calendar has no address on the server. Subscribe to it again.',
            );
        }

        return $collection;
    }
}
