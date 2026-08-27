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
 *   **What an instance id means is remembered, so a bare tombstone can be
 *   read.** Microsoft names a cancelled occurrence with its instance id and
 *   nothing else — no series, no start — and an id that resolves to nothing is a
 *   deletion that does nothing, which is how an occurrence deleted in Outlook
 *   stayed on the calendar. So every instance a window mentions, changed or not,
 *   is written into the series' CalendarEvent::$remoteInstances, and a tombstone
 *   the ordinary lookups cannot place is asked about there before it is applied:
 *   found, it becomes RemoteEvent::deletedInstance() and takes the ordinary
 *   instance path. The map is pruned to the horizon occurrences are drawn to,
 *   because an id for an instance no view can show answers no question.
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

        $touched    = 0;
        $seen       = [];
        $instances  = [];
        $identities = [];
        $written    = [];

        // What the window's instance ids mean, before anything is applied: a
        // driver reports these for the occurrences nothing happened to as well,
        // and they are not changes — only the record that lets a later tombstone
        // naming one of these ids be recognised at all.
        foreach ($changes->instances as $instance) {
            $identities[$instance->seriesRemoteId][$instance->remoteId] = $instance->recurrenceId;
        }

        foreach ($changes->events as $remote) {
            $remote = $this->recognisedInstance($calendar, $remote);

            if (true === $remote->isSeriesInstance()) {
                $seriesRemoteId = (string) $remote->seriesRemoteId;

                $instances[$seriesRemoteId][] = $remote;

                // An instance the window did say something about names itself
                // too, which is what makes the map true for a provider that
                // reports exceptions and nothing else.
                $identities[$seriesRemoteId][$remote->remoteId] = $remote->recurrenceId;

                continue;
            }

            $seen[] = $remote->remoteId;

            $touched += $this->applyOne($calendar, $user, $remote, $written);
        }

        // After every event in the window, so a series created and edited
        // between two polls — which arrives as the master and its moved
        // instance, in whichever order the provider felt like — has a row to
        // be patched onto.
        $touched += $this->applyInstances($calendar, $instances, $identities, $written, $wasFullRead);

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
     * A tombstone that names an instance rather than an event, said the way the
     * driver could not say it.
     *
     * Graph's `@removed` carries an id and nothing else, so the driver has no
     * way to tell an event from one occurrence of a series and reports the only
     * thing it knows. Applied as it stands it matches no row — an instance has
     * never been one — and the deletion does nothing at all, which is how an
     * occurrence somebody removed in Outlook went on being drawn. Looked up in
     * the map the pull itself wrote, it becomes the exclusion it always was.
     *
     * The lookup is on the map alone rather than after a row lookup, so an
     * ordinary deletion costs one query rather than two. The guard against a
     * master that somehow recorded its own id is the comparison below: turning a
     * series' deletion into an exclusion would leave the series on the calendar
     * forever, where failing to recognise an instance only leaves things as they
     * were before this existed.
     */
    private function recognisedInstance(Calendar $calendar, RemoteEvent $remote): RemoteEvent
    {
        if (false === $remote->isDeleted || true === $remote->isSeriesInstance()) {
            return $remote;
        }

        $master = $this->events->findOneByRemoteInstanceId($calendar, $remote->remoteId);

        if (null === $master || null === $master->remoteId || $master->remoteId === $remote->remoteId) {
            return $remote;
        }

        $recurrenceId = $this->recordedStart($master, $remote->remoteId);

        if (null === $recurrenceId) {
            return $remote;
        }

        return RemoteEvent::deletedInstance($remote->remoteId, $master->remoteId, $recurrenceId);
    }

    /**
     * The original start recorded against one instance id, or null when the
     * value stored there is not an instant.
     *
     * Total, because the column is JSON and a hand-edited row is one bad cast
     * from a TypeError inside a sweep. Converted to UTC as well as constructed
     * in it, for the reason EventInstanceEditor::instance() gives: the
     * constructor's zone applies only to a string that names none of its own, so
     * a value someone wrote with an offset on it would otherwise travel onward
     * in that offset.
     */
    private function recordedStart(CalendarEvent $master, string $instanceId): ?DateTimeImmutable
    {
        $recorded = $master->remoteInstances[$instanceId] ?? null;

        if (false === is_string($recorded) || '' === $recorded) {
            return null;
        }

        try {
            return new DateTimeImmutable($recorded, new DateTimeZone('UTC'))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
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
     * @param array<string,list<RemoteEvent>>               $instances  keyed by series remote id
     * @param array<string,array<string,DateTimeImmutable>> $identities what each instance id
     *                                                                  means, by series and
     *                                                                  then by instance id
     * @param array<string,CalendarEvent>                   $written    what the first pass wrote
     *
     * @return int how many series this changed
     */
    private function applyInstances(
        Calendar $calendar,
        array    $instances,
        array    $identities,
        array    $written,
        bool     $wasFullRead,
    ): int {
        $touched = 0;
        $now     = new DateTimeImmutable();

        // Every series the window named in either way. An identity with no patch
        // is the ordinary case for Microsoft — fifty-two unchanged occurrences a
        // year — and a patch with no identity is what a driver that only ever
        // reports exceptions gives.
        $seriesIds = array_unique([...array_keys($instances), ...array_keys($identities)]);

        foreach ($seriesIds as $seriesRemoteId) {
            $seriesRemoteId = (string) $seriesRemoteId;
            $remotes        = $instances[$seriesRemoteId] ?? [];
            $master         = $written[$seriesRemoteId] ?? $this->events->findOneByRemoteId($calendar, $seriesRemoteId);

            if (null === $master) {
                // Not a defect on either side: the series may predate this
                // calendar's first read, or belong to a calendar plMail does not
                // mirror. Worth an info line, because "one instance is at the
                // wrong time" is otherwise unexplainable — but only when
                // something was actually going to be applied, since a window
                // naming ids for a series nobody holds is nothing to report.
                if ([] !== $remotes) {
                    $this->logger->info('CalendarSync: an instance arrived for a series that is not here', [
                        'calendarId'     => $calendar->id,
                        'seriesRemoteId' => $seriesRemoteId,
                        'instances'      => count($remotes),
                    ]);
                }

                continue;
            }

            $this->rememberInstances($master, $identities[$seriesRemoteId] ?? [], $now);

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
     * Records which occurrence each of the remote's instance ids is, and forgets
     * the ones no view can reach.
     *
     * The whole point of the column, and it is written here rather than by the
     * driver because it is a column: the driver reports what it saw and the
     * engine owns what is stored, which is what keeps a driver free of Doctrine.
     *
     * An id whose original start another id already claims replaces it. A
     * provider that re-keys an occurrence when it becomes an exception —
     * Microsoft does, for some edits — would otherwise leave the dead id behind,
     * and a push that addressed it would patch a resource that is not there.
     *
     * Pruned to the horizon RecurrenceMaterialiser draws to, and the two agree
     * on purpose: an instance older than that has no occurrence row, cannot be
     * shown, and cannot be cancelled from anywhere, so its id is a byte with no
     * question behind it. Compared as strings because the stored spelling is
     * ISO 8601 in UTC, where lexical order is chronological order.
     *
     * @param array<string,DateTimeImmutable> $identities by instance id
     */
    private function rememberInstances(CalendarEvent $master, array $identities, DateTimeImmutable $now): void
    {
        if ([] === $identities) {
            return;
        }

        $known = $master->remoteInstances;

        foreach ($identities as $instanceId => $recurrenceId) {
            $start = $recurrenceId->setTimezone(new DateTimeZone('UTC'))
                ->format(CalendarEvent::INSTANCE_START_FORMAT);

            foreach (array_keys($known, $start, true) as $superseded) {
                unset($known[$superseded]);
            }

            $known[$instanceId] = $start;
        }

        $horizon = $now->setTimezone(new DateTimeZone('UTC'))
            ->modify(RecurrenceMaterialiser::HORIZON_PAST)
            ->format(CalendarEvent::INSTANCE_START_FORMAT);

        foreach ($known as $instanceId => $start) {
            if ($start < $horizon) {
                unset($known[$instanceId]);
            }
        }

        $master->remoteInstances = $known;
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
        //
        // And before the assignment, because it needs the UID this row is
        // being taken away from — see the method.
        $this->carryRekeyToLocalCopies($event, $user, $remote->uid);

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
     * When the remote re-keys a meeting plMail created, the meeting's other
     * local copies are re-keyed with it.
     *
     * ── Why this exists ──────────────────────────────────────────────────────
     * Google mints its own iCalUID and accepts none from us: `events.insert`
     * has no field for one, only `events.import` does — see
     * GoogleCalendarSyncDriver::push(). So a row created here and pushed comes
     * back under a UID nobody in this database has ever seen, and it is written
     * over the one this application minted, correctly, by the line that calls
     * this.
     *
     * What that quietly destroys is the identity the meeting's COPIES share.
     * One save can put a meeting on several calendars and every copy carries
     * the one UID deliberately — see EventCopyResolver, where the whole feature
     * rests on it — and UID plus start instant is the entirety of what
     * EventClusterer merges chips on. The moment the provider re-keys one copy,
     * the copies stop being the same meeting to every reader here: the calendar
     * draws the meeting twice on every day it touches, the editor opened on
     * either chip offers only that chip's own calendar ticked, and no later
     * edit can put them back together, because the editor is keyed on UID too.
     * A three-day event ticked onto two calendars therefore becomes six chips
     * that nothing can merge again.
     *
     * **Only rows the remote has never seen are carried.** A copy holding a
     * remoteId is identified at ITS provider by the UID it currently has, and
     * renaming it here would make it unmatchable there — which is the exact
     * harm the assignment below this call exists to prevent, done to somebody
     * else's row. A copy with no remote id has no other client's idea of its
     * identity to protect.
     *
     * A calendar that already holds a row under the new UID is left alone as
     * well: uniq_calendar_event_calendar_uid refuses two rows under one UID on
     * one calendar, and a re-key that violated it would turn a sync into a
     * failed flush.
     */
    private function carryRekeyToLocalCopies(CalendarEvent $event, User $user, string $uid): void
    {
        $was = $event->uid;

        if ('' === $was || $was === $uid || '' === $uid) {
            return;
        }

        foreach ($this->events->findByUidForUser($user, $was) as $copy) {
            $calendar = $copy->calendar;

            if ($copy === $event || null !== $copy->remoteId || null === $calendar) {
                continue;
            }

            if (null !== $this->events->findOneByUid($calendar, $uid)) {
                continue;
            }

            $copy->uid = $uid;

            // The canonical object carries the UID too — CalendarEventWriter
            // writes it there on every save — and an .ics export or a JMAP
            // client reads that copy rather than the column. Left behind, the
            // row would answer with two different identities depending on who
            // asked.
            $jscalendar        = $copy->jscalendar;
            $jscalendar['uid'] = $uid;
            $copy->jscalendar  = $jscalendar;

            $this->logger->info('CalendarSync: the remote re-keyed a meeting, carrying its local copies with it', [
                'calendarId' => $calendar->id,
                'eventId'    => $copy->id,
                'was'        => $was,
                'now'        => $uid,
            ]);
        }
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
