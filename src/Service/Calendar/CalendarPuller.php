<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\DTO\Calendar\CalendarChangeSet;
use App\Domain\DTO\Calendar\RemoteEvent;
use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\SyncState;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies one window of remote changes to the local rows.
 *
 * Split out of CalendarSyncService rather than living in it, because the two
 * are answerable for different things and only one of them is interesting to
 * read twice. The service owns the *order* — push, then pull, then the token —
 * and the recovery when a token dies. This owns what one RemoteEvent means
 * against one local row: which row it is, whether it is worth writing at all,
 * and what happens when it is not the only thing that changed.
 *
 * Four rules, in the order they are asked:
 *
 *   **Identity is the remote id, and the uid is the fallback.** A row is looked
 *   up by Calendar + remoteId first. Only when that finds nothing is the uid
 *   tried, and that second lookup is not a nicety: an invitation arriving by
 *   mail creates an event with the organiser's UID, and the same meeting on the
 *   connected calendar carries the same UID with a remote id plMail has never
 *   seen. Without the fallback, accepting an invite puts the meeting on the
 *   calendar twice.
 *
 *   **An unchanged etag is not a write.** Equal etags mean skip entirely — no
 *   write, no re-materialised occurrences, no updated_at. That is the cheap
 *   path for the ninety-odd per cent of a delta window that is an echo, and it
 *   is also a correctness rule: a pull that ran straight after a push would
 *   otherwise re-apply the remote's copy of the local edit over whatever the
 *   user typed in between.
 *
 *   **The remote wins, and a loss is never silent.** A row carrying an unsent
 *   local change that the remote has also changed is overwritten, and the
 *   discarded object is logged in full at warning level first. Remote-wins is
 *   chosen over last-write-wins because there is no clock both sides agree on
 *   — provider timestamps are the provider's, and comparing them to ours is
 *   comparing two machines' idea of now. Logging the loss is the part that
 *   matters: silently discarding a user's edit with no trace is the one
 *   outcome nobody can debug afterwards.
 *
 *   **A full read is authoritative.** With no token, the driver returns every
 *   live event and no tombstones, so a deletion that happened while the token
 *   was dead is knowable only by absence — and local rows carrying a remote id
 *   the full read did not mention are removed. Rows with no remote id are left
 *   alone: they have never been at the remote, so its silence says nothing
 *   about them.
 *
 *   **An instance of a series is a patch on it, never a row.** A RemoteEvent
 *   carrying a seriesRemoteId is one occurrence somebody moved or cancelled;
 *   written as its own event it would be a duplicate on the day it moved to,
 *   beside a series that still draws it where it was. So the window is applied
 *   in two passes — every event first, then the instances — which is also what
 *   makes an override that arrives *before* its master land: by the time the
 *   second pass runs, the row the first pass created is there to take it. An
 *   override whose series is nowhere is logged and dropped rather than turned
 *   into a row, because a master invented from one instance is a series with
 *   the wrong rule.
 *
 * Every write goes through CalendarEventWriter, so the JSCalendar object, the
 * projected columns and the materialised occurrences cannot disagree. Does not
 * flush — it joins the caller's unit of work.
 */
final readonly class CalendarPuller
{
    public function __construct(
        private CalendarEventRepository $events,
        private CalendarEventWriter     $writer,
        private RecurrenceRuleConverter $recurrence,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
    ) {
    }

    /**
     * @param bool $wasFullRead the changeset answers a null token, so absence
     *                          means deletion — see the fourth rule
     *
     * @return int how many local rows this changed
     */
    public function apply(Calendar $calendar, CalendarChangeSet $changes, bool $wasFullRead = false): int
    {
        $user = $calendar->usr;

        if (false === $user instanceof User) {
            return 0;
        }

        $touched   = 0;
        $seen      = [];
        $instances = [];
        $written   = [];

        foreach ($changes->events as $remote) {
            if (true === $remote->isSeriesInstance()) {
                $instances[(string) $remote->seriesRemoteId][] = $remote;

                continue;
            }

            $seen[] = $remote->remoteId;

            $touched += $this->applyOne($calendar, $user, $remote, $written);
        }

        // After every event in the window, so a series created and edited
        // between two polls — which arrives as the master and its moved
        // instance, in whichever order the provider felt like — has a row to
        // be patched onto.
        $touched += $this->applyInstances($calendar, $instances, $written, $wasFullRead);

        // An instance's own remote id is deliberately not in $seen. It never
        // named a local row, so listing it would protect nothing — and leaving
        // it out is what lets a full read clear away the duplicate rows the
        // moved instances of a series used to create.
        if (true === $wasFullRead) {
            $touched += $this->pruneMissing($calendar, $seen);
        }

        // Written last, after every event in the window has been applied. A
        // crash halfway through then re-reads the same window on the next run
        // — every operation here is idempotent, so that costs a repeat — where
        // storing the token first would step over whatever had not been
        // applied yet and never look at it again.
        if (null !== $changes->nextSyncToken) {
            $calendar->syncToken = $changes->nextSyncToken;
        }

        return $touched;
    }

    /**
     * @param array<string,CalendarEvent> $written the rows this window has
     *                                             already produced, by remote id
     *                                             — see applyInstances()
     */
    private function applyOne(Calendar $calendar, User $user, RemoteEvent $remote, array &$written): int
    {
        $existing = $this->events->findOneByRemoteId($calendar, $remote->remoteId)
            ?? $this->events->findOneByUid($calendar, $remote->uid);

        if (true === $remote->isDeleted) {
            return $this->removeLocal($existing, $remote);
        }

        $jscalendar = $remote->jscalendar;
        $startsAt   = $remote->startsAt;
        $endsAt     = $remote->endsAt;

        if (null === $jscalendar || null === $startsAt || null === $endsAt) {
            // A driver bug, not a remote one: a live event with no object or no
            // instants would be written as a row the range query can never
            // return, which presents as "the event synced but is not on the
            // calendar" and is indistinguishable from a query fault.
            $this->logger->warning('CalendarSync: driver returned an incomplete event, skipping', [
                'calendarId' => $calendar->id,
                'remoteId'   => $remote->remoteId,
            ]);

            return 0;
        }

        if (null !== $existing && true === $this->isUnchanged($existing, $remote)) {
            $written[$remote->remoteId] = $existing;

            return 0;
        }

        if (null !== $existing && true === $existing->syncState->wouldLoseALocalEdit()) {
            $this->logDiscardedEdit($existing, 'a remote change to the same event');
        }

        $event = $existing ?? new CalendarEvent();

        $this->write(
            event:      $event,
            calendar:   $calendar,
            user:       $user,
            remote:     $remote,
            jscalendar: $jscalendar,
            startsAt:   $startsAt,
            endsAt:     $endsAt,
        );

        $written[$remote->remoteId] = $event;

        return 1;
    }

    /**
     * The instances of each series in this window, as overrides on it.
     *
     * One write per series rather than one per instance: the patches are
     * collected first, so a series with a dozen moved occurrences is one
     * re-materialisation and not a dozen.
     *
     * The rows the first pass produced are consulted before the repository, and
     * that is not an optimisation. Nothing here flushes, so a series created a
     * moment ago is in the unit of work and not in the database — and
     * findOneByRemoteId() is a query, which would answer null and drop every
     * override that arrived in the same window as the series it belongs to.
     *
     * @param array<string,list<RemoteEvent>> $instances keyed by series remote id
     * @param array<string,CalendarEvent>     $written   what the first pass wrote
     *
     * @return int how many series this changed
     */
    private function applyInstances(Calendar $calendar, array $instances, array $written, bool $wasFullRead): int
    {
        $touched = 0;

        foreach ($instances as $seriesRemoteId => $remotes) {
            $master = $written[$seriesRemoteId] ?? $this->events->findOneByRemoteId($calendar, $seriesRemoteId);

            if (null === $master) {
                // Not a defect on either side: the series may predate this
                // calendar's first read, or belong to a calendar plMail does not
                // mirror. Worth an info line, because "one instance is at the
                // wrong time" is otherwise unexplainable.
                $this->logger->info('CalendarSync: an instance arrived for a series that is not here', [
                    'calendarId'     => $calendar->id,
                    'seriesRemoteId' => $seriesRemoteId,
                    'instances'      => count($remotes),
                ]);

                continue;
            }

            $patches = [];
            $zone    = $this->zoneOf($master);

            foreach ($remotes as $remote) {
                $recurrenceId = $remote->recurrenceId;
                $patch        = $this->patchOf($master, $remote);

                if (null === $recurrenceId || null === $patch) {
                    continue;
                }

                $patches[$this->recurrence->overrideKey($recurrenceId, $zone)] = $patch;
            }

            if ([] === $patches) {
                continue;
            }

            $this->writer->overrideInstances($master, $patches, $wasFullRead);

            $touched++;
        }

        return $touched;
    }

    /**
     * What one instance says about itself, as a JSCalendar PatchObject.
     *
     * Only what an occurrence row can carry, which is where it is and whether it
     * is off. A patch is not a place to park facts nothing reads — see
     * RecurrenceMaterialiser, which honours start, duration and a cancelled
     * status, and would silently ignore anything else put here.
     *
     * `start` is rendered in the SERIES' zone rather than the instance's,
     * because that is the zone the expander reads it back in (RFC 8984 §4.3.3
     * expands a rule in the event's own timeZone). An instance that claims a
     * different zone — Graph will, for an occurrence somebody moved while
     * travelling — would otherwise land at the right wall-clock time in the
     * wrong place.
     *
     * @return array<string,mixed>|null null when the instance says nothing usable
     */
    private function patchOf(CalendarEvent $master, RemoteEvent $remote): ?array
    {
        if (true === $remote->isDeleted) {
            return ['excluded' => true];
        }

        $startsAt = $remote->startsAt;

        if (null === $startsAt) {
            // A driver bug, like the incomplete event above: an override with no
            // start moves the instance nowhere and reads as a no-op.
            $this->logger->warning('CalendarSync: driver returned an instance with no start, skipping', [
                'calendarId' => $master->calendar?->id,
                'remoteId'   => $remote->remoteId,
            ]);

            return null;
        }

        $patch = [
            '@type' => 'Event',
            'start' => $startsAt->setTimezone($this->zoneOf($master))->format('Y-m-d\TH:i:s'),
        ];

        $jscalendar = $remote->jscalendar ?? [];

        // Taken from the driver's own object rather than recomputed from the two
        // instants, because that is the number it already wrote into the
        // instance's JSCalendar and a second subtraction here could only ever
        // disagree with it. Absent, the instance keeps the series' length.
        $duration = $jscalendar['duration'] ?? null;

        if (true === is_string($duration) && '' !== $duration) {
            $patch['duration'] = $duration;
        }

        $title = $jscalendar['title'] ?? null;

        if (true === is_string($title) && '' !== $title && $title !== $master->title) {
            $patch['title'] = $title;
        }

        $status = $jscalendar['status'] ?? null;

        if (EventStatus::Cancelled->value === $status) {
            $patch['status'] = EventStatus::Cancelled->value;
        }

        return $patch;
    }

    /**
     * The zone a series' local times are read in, UTC for anything unusable.
     *
     * A floating event has no zone and is expanded in UTC, which is what
     * floating means: the same wall clock everywhere. RecurrenceMaterialiser
     * makes the identical choice, and the two must agree — a key written in one
     * zone and looked up in another is an override that does nothing.
     */
    private function zoneOf(CalendarEvent $event): DateTimeZone
    {
        if (null === $event->timeZone || '' === $event->timeZone) {
            return new DateTimeZone('UTC');
        }

        try {
            return new DateTimeZone($event->timeZone);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * @return int 1 if a row went away, 0 if there was nothing to remove
     */
    private function removeLocal(?CalendarEvent $existing, RemoteEvent $remote): int
    {
        if (null === $existing) {
            // The normal case for a delta window replayed after a crash, and
            // for an event created and deleted between two polls. Not worth a
            // log line each: a sweep that says nothing when nothing happened is
            // a sweep whose warnings mean something.
            return 0;
        }

        if (true === $existing->syncState->wouldLoseALocalEdit()) {
            $this->logDiscardedEdit($existing, 'a remote deletion of the same event');
        }

        $this->em->remove($existing);

        return 1;
    }

    /**
     * Local rows the remote did not mention in a full read.
     *
     * @param list<string> $seenRemoteIds
     */
    private function pruneMissing(Calendar $calendar, array $seenRemoteIds): int
    {
        $orphaned = $this->events->findRemoteRowsNotIn($calendar, $seenRemoteIds);

        foreach ($orphaned as $event) {
            if (true === $event->syncState->wouldLoseALocalEdit()) {
                $this->logDiscardedEdit($event, 'a full resync that did not list it');
            }

            $this->em->remove($event);
        }

        return count($orphaned);
    }

    /**
     * Whether the remote is describing the revision already stored.
     *
     * Both etags have to be present for the answer to mean anything. A null on
     * either side is not "the same" — it is "this provider does not version its
     * events", and the safe reading of that is to write, which costs a
     * re-materialise and never loses an update.
     */
    private function isUnchanged(CalendarEvent $existing, RemoteEvent $remote): bool
    {
        return null !== $existing->remoteEtag
            && null !== $remote->etag
            && $existing->remoteEtag === $remote->etag;
    }

    /**
     * @param array<string,mixed> $jscalendar
     */
    private function write(
        CalendarEvent     $event,
        Calendar          $calendar,
        User              $user,
        RemoteEvent       $remote,
        array             $jscalendar,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
    ): void {
        // Before write(): the writer mints a UID for an event that has none,
        // and a locally minted UID on an event the remote already identifies
        // would make this row unmatchable by any other client — including the
        // invitation for the same meeting sitting in the mailbox.
        $event->uid = $remote->uid;

        $this->writer->write(
            event:             $event,
            calendar:          $calendar,
            user:              $user,
            title:             $this->title($jscalendar),
            startsAt:          $startsAt,
            endsAt:            $endsAt,
            timeZone:          $this->stringOrNull($jscalendar['timeZone'] ?? null),
            isAllDay:          true === ($jscalendar['showWithoutTime'] ?? false),
            location:          $this->location($jscalendar),
            description:       $this->stringOrNull($jscalendar['description'] ?? null),
            status:            EventStatus::tryFrom((string) ($jscalendar['status'] ?? '')) ?? EventStatus::Confirmed,
            recurrenceRule:    $this->recurrenceRule($jscalendar),
            jscalendarOverlay: $jscalendar,
        );

        $event->source     = EventSource::RemoteSync;
        $event->remoteId   = $remote->remoteId;
        $event->remoteEtag = $remote->etag;
        $event->syncState  = SyncState::Clean;
        $event->syncedAt   = new DateTimeImmutable();
    }

    /**
     * The loss, recorded in full.
     *
     * The whole JSCalendar object goes into the log, not a summary. This is the
     * only copy of what the user typed that will exist after the flush, and a
     * line saying "an edit was discarded" without saying which edit is a line
     * that answers no question anybody will actually ask.
     */
    private function logDiscardedEdit(CalendarEvent $event, string $because): void
    {
        $this->logger->warning('CalendarSync: a local edit was discarded, remote wins', [
            'calendarId' => $event->calendar?->id,
            'eventId'    => $event->id,
            'uid'        => $event->uid,
            'remoteId'   => $event->remoteId,
            'syncState'  => $event->syncState->value,
            'because'    => $because,
            'discarded'  => $event->jscalendar,
        ]);
    }

    /**
     * JSCalendar makes title optional; a calendar row with no label is not
     * usable.
     *
     * @param array<string,mixed> $jscalendar
     */
    private function title(array $jscalendar): string
    {
        $title = $this->stringOrNull($jscalendar['title'] ?? null);

        return null === $title ? 'Untitled' : $title;
    }

    /**
     * The first named location, which is what CalendarEvent::$location projects
     * — JSCalendar allows several and the column holds one.
     *
     * @param array<string,mixed> $jscalendar
     */
    private function location(array $jscalendar): ?string
    {
        $locations = $jscalendar['locations'] ?? null;

        if (false === is_array($locations)) {
            return null;
        }

        foreach ($locations as $location) {
            if (false === is_array($location)) {
                continue;
            }

            $name = $this->stringOrNull($location['name'] ?? null);

            if (null !== $name) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $jscalendar
     *
     * @return array<string,mixed>|null
     */
    private function recurrenceRule(array $jscalendar): ?array
    {
        $rules = $jscalendar['recurrenceRules'] ?? null;

        if (false === is_array($rules) || false === is_array($rules[0] ?? null)) {
            return null;
        }

        return $rules[0];
    }

    /**
     * A JSCalendar object arrives as decoded JSON, so any key can hold anything
     * — a driver mapping a hostile .ics is one bad cast away from a TypeError
     * that fails the whole sweep.
     */
    private function stringOrNull(mixed $value): ?string
    {
        if (false === is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }
}
