<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\DTO\Calendar\CalendarChangeSet;
use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Calendar\RemoteCalendar;
use App\Domain\DTO\Calendar\RemoteWriteResult;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;

/**
 * Talks to one kind of remote calendar.
 *
 * Implementations are auto-tagged app.calendar_sync_driver and resolved through
 * CalendarSyncDriverRegistry — the same shape as MailSenderRegistry and
 * IntegrationDriverRegistry. Adding a provider is a directory under Service/,
 * not an edit to anything here or in the engine.
 *
 * The engine on the other side of this interface (CalendarSyncService,
 * CalendarPuller, CalendarPusher) knows nothing about HTTP, OAuth, CalDAV or
 * any provider's resource shapes, and that is the property worth protecting.
 * Six contracts hold it up, and every one of them exists because breaking it
 * would put a provider concept into the engine:
 *
 *   **Every id is opaque.** A RemoteCalendar::$remoteId, a RemoteEvent::$etag,
 *   a CalendarChangeSet::$nextSyncToken — whatever a driver puts in one comes
 *   back to it byte for byte. Nothing outside the driver parses, orders,
 *   truncates or compares one for anything but equality. A Google resource id,
 *   a Graph delta link and a CalDAV href are all just strings here.
 *
 *   **JSCalendar is the only event vocabulary.** A driver maps its provider's
 *   representation to and from RFC 8984 at its own boundary. Nothing above
 *   this line has ever seen a VEVENT or a Graph `event` resource, and nothing
 *   should learn to.
 *
 *   **Times crossing this boundary are UTC DateTimeImmutable.** The JSCalendar
 *   object keeps its own LocalDateTime-plus-zone as the spec requires;
 *   RemoteEvent::$startsAt and $endsAt are instants, because that is what the
 *   local columns and every range query are.
 *
 *   **Every failure is a CalendarSyncException or a subclass.** Transport
 *   exceptions, JSON errors, non-2xx statuses and XML parse failures are all
 *   translated here, so callers never see an HTTP concern and never have to
 *   guess whether a null meant "empty" or "broken". Which subclass is chosen
 *   is the driver's most consequential decision — see the hierarchy's docblock;
 *   when the body does not say clearly enough, raise the unclassified base
 *   rather than guessing "permanent".
 *
 *   **Nothing here flushes, persists or touches Doctrine.** A driver reads the
 *   two entities it is handed and returns DTOs. Calendar::$syncToken,
 *   CalendarEvent::$remoteId and every other column are the engine's to write,
 *   which is what makes "the token is stored only after the whole window
 *   applied" a rule one class can keep.
 *
 *   **A recurring series is one event, and a changed instance is an override of
 *   it.** A driver never emits an instance as an event of its own: the rule
 *   lives on the master and RecurrenceMaterialiser draws the instances from it,
 *   so a second row for the moved one is a duplicate on the day it moved to
 *   beside a series that still draws it where it was. There are two ways to say
 *   it and which one a driver uses is decided by its provider, not by taste:
 *
 *     A provider whose resource is *atomic* — CalDAV, where every component
 *     sharing a UID arrives in one .ics — puts the whole recurrenceOverrides
 *     map inside the master's own JSCalendar object. That map is complete by
 *     construction, so the engine replaces what it holds and an instance moved
 *     back is a map the resource no longer mentions.
 *
 *     A provider whose instances are *separate resources* — Google's
 *     `recurringEventId`, Graph's `type: exception` — returns a RemoteEvent
 *     carrying $seriesRemoteId and $recurrenceId. The engine files it onto the
 *     master's map without touching the rest of it, because a delta window is
 *     only ever a statement about the instances it names. An instance that is
 *     off is RemoteEvent::deletedInstance(), never deleted(): the series is
 *     alive, and a tombstone against a row that does not exist does nothing at
 *     all. $recurrenceId is the instance's ORIGINAL start, not the moved one —
 *     it is the only name an instance keeps once it has been dragged.
 *
 *     Such a provider also reports, in CalendarChangeSet::$instances, which
 *     occurrence each instance id it mentioned stands for — including the ones
 *     nothing happened to. That is not a change and is not applied as one; the
 *     engine only remembers it, in CalendarEvent::$remoteInstances, so that a
 *     later tombstone carrying an id and nothing else can be turned back into
 *     deletedInstance() before it is applied. A driver that cannot say it (a
 *     provider that never names an instance until it becomes an exception, which
 *     is Google) leaves the list empty and loses nothing: its own tombstones
 *     already name the series and the original start.
 *
 *   An override whose series the engine holds no row for is logged and dropped.
 *   Creating the master from an instance would invent a series with one
 *   occurrence and the wrong rule.
 *
 * A driver is also entitled to assume things, and they are worth stating so no
 * implementation defends against them twice: it is never asked to push to a
 * calendar whose isReadOnly is true, it is never handed a Calendar whose
 * source it did not claim in supports(), and it is never called concurrently
 * for the same calendar — the sweep dispatches one message per calendar and
 * the handler is the only caller.
 */
interface CalendarSyncDriverInterface
{
    /**
     * Whether this driver speaks for the account or connection behind a source.
     *
     * Answered from CalendarSource::mailProvider() or
     * ::integrationProvider(), never by reaching into the entity for a URL or a
     * hostname. The registry takes the first driver that says yes, so a driver
     * that claims too broadly silently steals another's calendars.
     *
     * Must not perform I/O. It is called once per registered driver on every
     * sync of every calendar.
     */
    public function supports(CalendarSource $source): bool;

    /**
     * Every calendar the source can see, whether or not plMail mirrors it.
     *
     * Called before any Calendar row exists — that is the whole reason this
     * takes a CalendarSource and not a Calendar. The subscribe screen renders
     * the result and the user ticks what they want; nothing here creates
     * anything.
     *
     * An empty list is a legitimate answer and means the account has no
     * calendars, not that something failed. Failure is an exception.
     *
     * @return list<RemoteCalendar>
     *
     * @throws CalendarSyncException
     */
    public function discover(CalendarSource $source): array;

    /**
     * What changed at the remote since $syncToken.
     *
     * A null token means "everything": the caller has no usable position, and
     * the driver must return every event that currently exists on the calendar.
     * It must NOT return tombstones for a full read — there is nothing to
     * tombstone against, and the engine treats a full read as authoritative,
     * removing local rows the listing did not mention.
     *
     * A cancelled *instance* is the exception, and deliberately so: it is not a
     * tombstone against a row, it is a fact about a series that is still there,
     * and a full read that dropped it would resurrect every instance the user
     * had cancelled. RemoteEvent::deletedInstance() is therefore returned by a
     * full read as well as by a delta window.
     *
     * $syncToken is passed explicitly rather than read off $calendar so the
     * engine can re-pull with null after a resync without first writing the
     * cleared token to the database. The two disagree exactly once, on that
     * second call, and that is deliberate.
     *
     * The driver follows its provider's paging internally and returns one
     * complete window — see CalendarChangeSet. $nextSyncToken is the position
     * after the last event in $events, never an intermediate page cursor.
     *
     * A dead token is reported by returning
     * CalendarChangeSet::resyncRequired(), not by throwing: token expiry is a
     * normal outcome of polling a calendar nobody touched for a week. Throwing
     * CalendarResyncRequiredException is permitted and handled identically, for
     * the case where the discovery happens too deep to return from.
     *
     * @throws CalendarSyncException
     */
    public function pull(Calendar $calendar, ?string $syncToken): CalendarChangeSet;

    /**
     * Create or update one event at the remote.
     *
     * Which of the two it is, is read off $event->remoteId: null means create,
     * anything else means update that resource. The engine does not pass a
     * flag, because $remoteId is the fact and a flag would be a second copy of
     * it that can disagree.
     *
     * The event's canonical JSCalendar object is $event->jscalendar; the
     * projected columns beside it (title, startsAt, timeZone, status …) are
     * derived from it and are consistent with it, because CalendarEventWriter
     * is the only thing that writes either. A driver may read whichever is
     * more convenient.
     *
     * **A series is pushed with its instances, not without them.** The event's
     * recurrenceOverrides are part of what this row says about itself, and the
     * two halves of a series travel differently depending on the provider — the
     * same split the pull makes, and for the same reason. A driver whose
     * resource is atomic writes the overrides inside it and is finished. A
     * driver whose instances are separate resources must, after the master's own
     * write, find the provider's instance for each override's ORIGINAL start and
     * write it there: moved, renamed or lengthened by a patch, cancelled by an
     * `{"excluded": true}`. InstanceOverride::listOf() resolves the stored map
     * into the instants both of those need. Skipping it does not lose the
     * change, but it does mean the change is visible in plMail alone until a
     * full read that carries any exception for that series replaces the whole
     * map and takes the local patch with it.
     *
     * Failing to place ONE instance must not fail the push. The master has
     * already been written by then, and a driver that threw would leave the
     * engine unable to record the id it just came back with — which on a create
     * is a second copy of the meeting on the next sweep. An instance that cannot
     * be found or cannot be written is logged and the rest go out.
     *
     * A driver may read CalendarEvent::$remoteInstances to address an instance
     * directly, and must not write it: like every other column it is the
     * engine's, and the pull that learned the id is what keeps it true.
     *
     * Returns what the remote assigned. The returned remoteId is stored
     * verbatim even on an update, so a provider that re-keys an event on edit
     * does not orphan the local row.
     *
     * Never called for a calendar whose isReadOnly is true; the engine asserts
     * that before it gets here.
     *
     * @throws CalendarResyncRequiredException when the remote has re-keyed the
     *                                         calendar under us and the write
     *                                         cannot be placed
     * @throws CalendarSyncException
     */
    public function push(Calendar $calendar, CalendarEvent $event): RemoteWriteResult;

    /**
     * Remove one event from the remote.
     *
     * Idempotent by contract: an event already gone is a success, not a
     * failure. Every provider answers 404 or 410 to the second delete, the
     * engine retries jobs, and treating that as an error would leave a local
     * row stuck in PendingDelete forever, re-attempting the same delete on
     * every sweep.
     *
     * Returns nothing — there is no state left to report. The engine removes
     * the local row once this returns without throwing.
     *
     * Never called for a read-only calendar, and never for an event whose
     * remoteId is null: an event the remote never saw is deleted locally
     * without asking anyone.
     *
     * @throws CalendarSyncException
     */
    public function delete(Calendar $calendar, CalendarEvent $event): void;
}
