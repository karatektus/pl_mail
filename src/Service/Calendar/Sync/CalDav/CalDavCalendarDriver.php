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
 * Both are CalDavCollectionReader's, which the tasks' driver reads through too.
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
    public function __construct(
        private CalDavClient           $client,
        private CalDavDiscovery        $discovery,
        private CalDavCollectionReader $reader,
        private CalDavEventConverter   $converter,
        private LoggerInterface        $logger,
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
        // Nextcloud as a calendar source only. A bare Provider is what the
        // connect and test paths ask, and the Nextcloud connection is made and
        // verified as a file service by NextcloudDriver — answering yes here
        // would put it in the "connect a CalDAV server" form as well (#158).
        return match (true) {
            $subject instanceof Provider       => Provider::CalDav === $subject,
            $subject instanceof CalendarSource => in_array(
                $subject->integrationProvider(),
                [Provider::CalDav, Provider::Nextcloud],
                true,
            ),
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
        $url      = $endpoint->collection ?? (string) $endpoint->home;

        $calendars = [];

        foreach ($this->reader->listCollections($integration, $endpoint) as $resource) {
            if (false === $resource->isCalendarCollection() || false === $this->holdsEvents($resource)) {
                continue;
            }

            $href = $this->client->absolutise($resource->href, $url);

            $calendars[] = new RemoteCalendar(
                remoteId:   $href,
                name:       $this->nameOf($resource, $href),
                color:      $this->colourOf($resource),
                timeZone:   $this->timeZoneOf($resource),
                isReadOnly: $resource->isReadOnly(),
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

        $read = $this->reader->read($integration, $collection, $syncToken, 'VEVENT');

        if (true === $read->requiresFullResync) {
            return CalendarChangeSet::resyncRequired();
        }

        $events = [];

        foreach ($read->changes as $change) {
            if (true === $change->isDeleted()) {
                $events[] = RemoteEvent::deleted($change->href);

                continue;
            }

            $event = $this->converter->toRemoteEvent((string) $change->data, $change->href, $change->etag);

            if (null === $event) {
                $this->logger->info('CalDav: skipped a resource that is not a usable event', ['href' => $change->href]);

                continue;
            }

            $events[] = $event;
        }

        return new CalendarChangeSet($events, $read->nextSyncToken);
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

        return new RemoteWriteResult($href, $this->client->etagAfterWrite($integration, $href, $response['headers']));
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
