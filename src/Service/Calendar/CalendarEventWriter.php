<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\SyncState;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place an event is written, so the canonical JSCalendar object and the
 * columns projected from it cannot disagree.
 *
 * Without this there are two truths: a caller sets $title and forgets
 * jscalendar['title'], the calendar looks right, and the .ics export is blank.
 * Every writer — the controller now, extraction and CalDAV sync later — goes
 * through here, and re-materialises occurrences as part of the same call
 * because an event whose rows are stale is an event that is not in the view.
 *
 * Does not flush; it joins the caller's unit of work.
 */
final readonly class CalendarEventWriter
{
    public function __construct(
        private RecurrenceMaterialiser $materialiser,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string,mixed>|null $recurrenceRule    a JSCalendar RecurrenceRule, or null for a one-off
     * @param array<string,mixed>|null $jscalendarOverlay a canonical object an extractor already built
     */
    public function write(
        CalendarEvent     $event,
        Calendar          $calendar,
        User              $user,
        string            $title,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?string           $timeZone,
        bool              $isAllDay = false,
        ?string           $location = null,
        ?string           $description = null,
        EventStatus       $status = EventStatus::Confirmed,
        ?array            $recurrenceRule = null,
        ?array            $jscalendarOverlay = null,
    ): CalendarEvent {
        $event->calendar = $calendar;
        $event->usr      = $user;
        $event->title    = $title;
        $event->location = $location;
        $event->status   = $status;
        $event->isAllDay = $isAllDay;
        $event->timeZone = true === $isAllDay ? null : $timeZone;
        $event->startsAt = $startsAt->setTimezone(new DateTimeZone('UTC'));
        $event->endsAt   = $endsAt->setTimezone(new DateTimeZone('UTC'));

        if ('' === $event->uid) {
            $event->uid = $this->newUid();
        }

        $event->jscalendar = $this->toJsCalendar($event, $description, $recurrenceRule);

        // An extractor has already built the canonical object, and it carries
        // things no parameter list should have to thread through —
        // participants, alerts, the sender's own recurrence rule. It wins,
        // with the derived version as the floor. Merged HERE rather than by
        // the caller afterwards so the event is complete before occurrences
        // are materialised from it.
        if (null !== $jscalendarOverlay) {
            $stored = $event->jscalendar;

            $event->jscalendar = array_merge($event->jscalendar, $jscalendarOverlay);
            $event->jscalendar = $this->keepAnswersAlreadyGiven($event->jscalendar, $stored);
        }

        $this->em->persist($event);

        // Occurrences are what a view reads, so they are not optional and not
        // something a caller can forget: the event is not visible until they
        // exist, and stale ones are worse than none.
        $this->materialiser->materialise($event);

        return $event;
    }

    /**
     * File per-instance patches onto a series and redraw it.
     *
     * The other half of write(): a remote reports a moved or cancelled instance
     * separately from the series it belongs to, so there is no full object to
     * write and nothing to project onto the columns — the series' own title,
     * times and rule are unchanged, and only its recurrenceOverrides are not.
     * Going through the writer anyway is what keeps "the object and the
     * occurrences cannot disagree" true: the patches decide which instances are
     * drawn where, so they are worth nothing until the occurrences are written
     * again from them.
     *
     * $replaceExisting is the difference between the two kinds of window a
     * provider can give. A delta window names the instances that changed and
     * says nothing about the others, so its patches merge; a full read is the
     * whole truth about every series it mentions, so its patches replace — which
     * is the only way an instance that was moved back stops being drawn where it
     * used to be.
     *
     * Does not flush, like everything else here.
     *
     * @param array<string,array<string,mixed>> $patches keyed by the instance's
     *                                                   original LocalDateTime,
     *                                                   which is what
     *                                                   RecurrenceMaterialiser
     *                                                   looks them up by
     */
    public function overrideInstances(CalendarEvent $event, array $patches, bool $replaceExisting = false): void
    {
        $stored = $event->jscalendar['recurrenceOverrides'] ?? null;
        $kept   = true === $replaceExisting || false === is_array($stored) ? [] : $stored;

        $jscalendar = $event->jscalendar;
        $overrides  = array_merge($kept, $patches);

        // Removed rather than left empty: an empty map is not a fact about the
        // series, and leaving one behind would make every event that ever had an
        // override carry a key that reads as though it still does.
        if ([] === $overrides) {
            unset($jscalendar['recurrenceOverrides']);
        } else {
            $jscalendar['recurrenceOverrides'] = $overrides;
        }

        $event->jscalendar = $jscalendar;

        $this->em->persist($event);
        $this->materialiser->materialise($event);
    }

    /**
     * A user edit is a decision. Recording it is what stops a later message
     * about the same booking quietly reverting what the user just fixed.
     */
    public function markUserEdited(CalendarEvent $event): void
    {
        if (true === $event->isExtracted()) {
            $event->isUserEdited = true;
        }
    }

    /**
     * This row has changed here and the remote has not been told.
     *
     * Called by whatever made the change, never by write() itself — write() is
     * also how the sync engine applies what it just *read* from the remote, and
     * marking there would make every pull queue a push of the remote's own
     * data straight back at it.
     *
     * A no-op on a calendar that mirrors nothing, so the editor and the
     * reconciler can call it unconditionally rather than each carrying its own
     * copy of the "is this synced?" question.
     */
    public function markLocallyChanged(CalendarEvent $event): void
    {
        if (null === $event->calendar || false === $event->calendar->isSynced()) {
            return;
        }

        $event->syncState = $event->syncState->afterLocalEdit();
    }

    /**
     * A create that has never been at the remote, so the push is a POST rather
     * than a PUT.
     *
     * Separate from markLocallyChanged() because afterLocalEdit() cannot tell
     * the two apart from the state alone — a brand-new event is Clean, like an
     * event in step with the remote — and guessing from a null remoteId would
     * be wrong for the one case that matters: an event whose create is still
     * pending is also remoteId-less.
     */
    public function markLocallyCreated(CalendarEvent $event): void
    {
        if (null === $event->calendar || false === $event->calendar->isSynced()) {
            return;
        }

        $event->syncState = SyncState::PendingCreate;
    }

    /**
     * Delete an event that a remote also holds — the row survives until the
     * remote has been told, because the row is the only record that it is
     * there to tell.
     *
     * The occurrences go now. Every calendar view reads occurrences and none
     * reads events, so dropping them is what makes the deletion look immediate
     * without any view having to learn that PendingDelete exists. Returns
     * whether the caller still has to remove the entity itself: false means
     * this row is now the pusher's problem.
     *
     * A hand-made event on a local calendar, or one the remote never saw, is
     * not this method's business — it answers true and the caller removes it,
     * which is what the delete path did before sync existed.
     */
    public function markLocallyDeleted(CalendarEvent $event): bool
    {
        if (null === $event->calendar || false === $event->calendar->isSynced()) {
            return true;
        }

        if (null === $event->remoteId) {
            return true;
        }

        $event->syncState = SyncState::PendingDelete;

        $this->materialiser->clear($event);

        return false;
    }

    /**
     * An answer already recorded here is not un-answered by re-reading the mail
     * that asked the question.
     *
     * The invitation is the organiser's copy of the question, and it says
     * NEEDS-ACTION about us forever — that is what it said when it was sent.
     * Re-running extraction over stored mail is routine (a mapper improves, a
     * bug is repaired, `app:backfill events`), and without this every RSVP the
     * user had given reverted to unanswered while the organiser, who was told
     * at the time, went on knowing better than the screen.
     *
     * Only ever keeps; never invents. An incoming entry that states an actual
     * answer wins, because that is the organiser's updated attendee list coming
     * back to us and it knows about replies from people other than this user.
     *
     * @param array<string,mixed> $merged
     * @param array<string,mixed> $stored
     *
     * @return array<string,mixed>
     */
    private function keepAnswersAlreadyGiven(array $merged, array $stored): array
    {
        $incoming = $merged['participants'] ?? null;
        $previous = $stored['participants'] ?? null;

        if (false === is_array($incoming) || false === is_array($previous)) {
            return $merged;
        }

        foreach ($incoming as $key => $participant) {
            if (false === is_array($participant) || false === is_array($previous[$key] ?? null)) {
                continue;
            }

            $answered = (string) ($previous[$key]['participationStatus'] ?? '');
            $arriving = (string) ($participant['participationStatus'] ?? '');

            if ('' === $answered || 'needs-action' === $answered) {
                continue;
            }

            if ('' === $arriving || 'needs-action' === $arriving) {
                $incoming[$key]['participationStatus'] = $answered;
            }
        }

        $merged['participants'] = $incoming;

        return $merged;
    }

    /**
     * @param array<string,mixed>|null $recurrenceRule
     *
     * @return array<string,mixed>
     */
    private function toJsCalendar(
        CalendarEvent $event,
        ?string       $description,
        ?array        $recurrenceRule,
    ): array {
        $zone = $event->timeZone ?? 'UTC';

        // JSCalendar times are LocalDateTime — no offset, no trailing Z — with
        // timeZone naming the zone they are local to (RFC 8984 §4.1.2).
        $local = $event->startsAt->setTimezone(new DateTimeZone($zone));

        $jscalendar = [
            '@type'    => 'Event',
            'uid'      => $event->uid,
            'title'    => (string) $event->title,
            'start'    => $local->format('Y-m-d\TH:i:s'),
            'duration' => $this->duration($event),
            'status'   => $event->status->value,
            'privacy'  => $event->privacy->value,
        ];

        if (null !== $event->timeZone) {
            $jscalendar['timeZone'] = $event->timeZone;
        }

        if (true === $event->isAllDay) {
            $jscalendar['showWithoutTime'] = true;
        }

        if (null !== $description && '' !== $description) {
            $jscalendar['description'] = $description;
        }

        if (null !== $event->location && '' !== $event->location) {
            $jscalendar['locations'] = [
                '1' => ['@type' => 'Location', 'name' => $event->location],
            ];
        }

        if (null !== $recurrenceRule) {
            $jscalendar['recurrenceRules'] = [$recurrenceRule];
        }

        // Overrides survive an edit: they are per-instance decisions the user
        // made, and rewriting the series is not a reason to lose them.
        if (true === isset($event->jscalendar['recurrenceOverrides'])) {
            $jscalendar['recurrenceOverrides'] = $event->jscalendar['recurrenceOverrides'];
        }

        // Participants survive for the same reason, and one more: they carry
        // the RSVP. This object is rebuilt from the columns, the editor has no
        // participants field, and without this line correcting the title of a
        // meeting silently un-answers an invitation that was already accepted
        // — locally only, since the organiser was told long ago.
        if (true === isset($event->jscalendar['participants'])) {
            $jscalendar['participants'] = $event->jscalendar['participants'];
        }

        return $jscalendar;
    }

    /** ISO 8601 duration, which is how JSCalendar says how long something is. */
    private function duration(CalendarEvent $event): string
    {
        $seconds = max(0, $event->endsAt->getTimestamp() - $event->startsAt->getTimestamp());

        if (0 === $seconds) {
            return 'PT0S';
        }

        $days    = intdiv($seconds, 86400);
        $hours   = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest    = $seconds % 60;

        $duration = 'P' . (0 < $days ? $days . 'D' : '');
        $time     = (0 < $hours ? $hours . 'H' : '')
            . (0 < $minutes ? $minutes . 'M' : '')
            . (0 < $rest ? $rest . 'S' : '');

        return '' === $time ? $duration : $duration . 'T' . $time;
    }

    /**
     * Globally unique and ours. The domain part is a literal rather than the
     * install's hostname on purpose: a UID must not change when someone puts
     * the app behind a different name, because it is the identity every other
     * calendar matches this event on.
     */
    private function newUid(): string
    {
        return bin2hex(random_bytes(16)) . '@plmail';
    }
}
