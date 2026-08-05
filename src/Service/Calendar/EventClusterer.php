<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\DTO\Calendar\OccurrenceCluster;
use App\Domain\Enum\Calendar\EventStatus;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use DateTimeImmutable;

/**
 * What makes two rows the same meeting, and the one place that decides it.
 *
 * A meeting can reach plMail twice by two honest routes at once — extracted
 * from an invitation onto the account's calendar, and mirrored from the
 * provider onto a Remote calendar — and both rows are correct. CalendarPuller
 * already falls back from remoteId to uid, but scoped to one calendar, and
 * these are two, so the second row is created rather than matched. Nothing here
 * changes that: the rows stay, and the duplication is answered on the screen.
 *
 * **UID is the identity, and the only honest key.** Matching on title and time
 * would collapse a weekly 1:1 held with two different people at the same hour
 * into one chip, and that is a meeting quietly disappearing from a calendar,
 * which is the worst shape a calendar bug takes. The grouping key is the UID
 * together with the start instant, because two occurrences of one series are
 * the same event and not the same meeting.
 *
 * **A cluster is only merged while its members agree**, on exactly the five
 * things a user would notice on a chip: start, end, title, all-day and whether
 * it has been called off. The moment they disagree the cluster splits back into
 * clusters of one and the views draw a chip each — deliberately, because a
 * merged chip that quietly picks a winner hides a real disagreement (an update
 * that reached one path and not the other) behind a tidier UI. That is the
 * difference between a merge and a cover-up, and it is also why disagreement
 * splits the WHOLE group rather than merging the sub-group that happens to be
 * in the majority: a majority is a winner picked with extra steps.
 *
 * Recurrence is deliberately NOT one of the five. Two copies where one repeats
 * and the other does not agree about the occurrence they share and about
 * nothing else — and the copy that repeats draws its own chips on every later
 * day, with no partner to merge with, which is exactly the visible signal that
 * the two differ. Merging the one occurrence they genuinely share is honest
 * about what is shared.
 *
 * Cancellation is read from the occurrence row AND from the event's status,
 * because the range query drops cancelled occurrence rows before a view ever
 * sees them: the disagreement that actually reaches the screen is a status of
 * cancelled on one copy and confirmed on the other, and merging those would
 * draw a live meeting that one of the two paths has been told is off.
 *
 * copiesOf() answers the same question about events rather than occurrences,
 * for the editor. It compares the same five fields through the same private
 * signature, so the list of copies the editor offers cannot drift from the
 * members the chip merged — two implementations of "the same meeting" would
 * agree until one of them learned about a sixth field.
 */
final readonly class EventClusterer
{
    public function __construct(
        private CalendarEventRepository $events,
    ) {
    }

    /**
     * The chips a range of occurrences draws, in the order they arrived.
     *
     * Order is preserved because the caller's query already sorted by start and
     * then by id, and every member of a group shares its start — so grouping
     * cannot reorder the result, and the day-grouping downstream stays stable
     * between renders.
     *
     * @param list<CalendarEventOccurrence> $occurrences
     *
     * @return list<OccurrenceCluster>
     */
    public function cluster(array $occurrences): array
    {
        /** @var array<string, non-empty-list<CalendarEventOccurrence>> $grouped */
        $grouped = [];

        foreach ($occurrences as $occurrence) {
            $grouped[$this->keyOf($occurrence)][] = $occurrence;
        }

        $clusters = [];

        foreach ($grouped as $group) {
            if (true === $this->membersAgree($group)) {
                $clusters[] = OccurrenceCluster::of($group);

                continue;
            }

            foreach ($group as $member) {
                $clusters[] = OccurrenceCluster::of([$member]);
            }
        }

        return $clusters;
    }

    /**
     * Every row this user holds that is the same meeting as $event — itself
     * included, always first-class rather than a special case.
     *
     * Re-derived from the UID rather than from a cluster id threaded through the
     * URL: a cluster is a fact about the data at the moment it is read, and an
     * id minted for one render would be a claim about the data that the next
     * write can silently falsify.
     *
     * Scoped to VISIBLE calendars, plus the opened event whatever its calendar,
     * so the list the editor offers is exactly the set of chips that were merged
     * into the one the user clicked. A copy on a calendar the user has hidden was
     * never drawn, so offering to write it would be an edit to something not on
     * screen.
     *
     * @return non-empty-list<CalendarEvent>
     */
    public function copiesOf(CalendarEvent $event, User $user): array
    {
        // A new event has no identity yet, and an event with no UID is not
        // matchable against anything — both are a cluster of one.
        if ('' === $event->uid) {
            return [$event];
        }

        $signature = $this->signatureOf($event, $event->startsAt, $event->endsAt);
        $copies    = [];
        $found     = false;

        foreach ($this->events->findByUidForUser($user, $event->uid) as $candidate) {
            if ($candidate->id === $event->id) {
                $copies[] = $event;
                $found    = true;

                continue;
            }

            if (null === $candidate->calendar || false === $candidate->calendar->isVisible) {
                continue;
            }

            if ($this->signatureOf($candidate, $candidate->startsAt, $candidate->endsAt) === $signature) {
                $copies[] = $candidate;
            }
        }

        // A brand-new row is not in the query's answer yet, and neither is one
        // whose calendar the user hid between the render and the save. Either
        // way the event being edited is a member of its own cluster.
        if (false === $found) {
            array_unshift($copies, $event);
        }

        return $copies;
    }

    /**
     * The copies an edit is allowed to reach: the ones the user ticked, minus
     * the ones nothing may write.
     *
     * The read-only filter is here and not only in the template. The checkbox is
     * rendered disabled and unticked, so an ordinary submit never names one —
     * but a disabled checkbox is a statement to a browser, not a guarantee to a
     * server, and a mirror of somewhere that does not accept writes back must
     * refuse a crafted request as flatly as it refuses a click.
     *
     * @param list<CalendarEvent> $copies
     * @param array<mixed>        $chosenIds as posted, so entirely untrusted
     *
     * @return list<CalendarEvent>
     */
    public function chosen(array $copies, array $chosenIds): array
    {
        $wanted = [];

        foreach ($chosenIds as $chosenId) {
            if (true === is_scalar($chosenId)) {
                $wanted[] = (int) $chosenId;
            }
        }

        $chosen = [];

        foreach ($copies as $copy) {
            if (true === $copy->calendar?->isReadOnly) {
                continue;
            }

            if (true === in_array((int) $copy->id, $wanted, true)) {
                $chosen[] = $copy;
            }
        }

        return $chosen;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * UID plus start instant.
     *
     * The timestamp leads and is digits, so the separator cannot be read into
     * it — a UID is free-form text and may contain anything, including the
     * separator, and "same key by coincidence" here means two unrelated
     * meetings drawn as one.
     */
    private function keyOf(CalendarEventOccurrence $occurrence): string
    {
        return sprintf('%d|%s', $occurrence->startsAt?->getTimestamp() ?? 0, (string) $occurrence->event?->uid);
    }

    /**
     * @param non-empty-list<CalendarEventOccurrence> $members
     */
    private function membersAgree(array $members): bool
    {
        if (1 === count($members)) {
            return true;
        }

        $signature = null;
        $calendars = [];

        foreach ($members as $member) {
            // Two rows on one calendar are two meetings, by construction:
            // uniq_calendar_event_calendar_uid makes a UID unique within a
            // calendar, so a repeat here is one series with two occurrences at
            // the same instant — an instance dragged onto a sibling's time.
            // Merging those would erase one of them from the view.
            //
            // By object identity rather than by id. Doctrine's identity map
            // gives one Calendar instance per row within a unit of work, and
            // the range query fetch-joins them, so identity is exact — while an
            // id is null on anything not yet persisted, and two nulls would
            // read as one calendar and split a cluster that is perfectly fine.
            $calendar = null === $member->calendar ? 0 : spl_object_id($member->calendar);

            if (true === in_array($calendar, $calendars, true)) {
                return false;
            }

            $calendars[] = $calendar;

            $current   = $this->occurrenceSignature($member);
            $signature ??= $current;

            if ($current !== $signature) {
                return false;
            }
        }

        return true;
    }

    /**
     * What a user would notice about one occurrence, as a comparable value.
     *
     * @return array<string,mixed>
     */
    private function occurrenceSignature(CalendarEventOccurrence $occurrence): array
    {
        $signature = $this->signatureOf($occurrence->event, $occurrence->startsAt, $occurrence->endsAt);

        $signature['calledOff'] = true === $signature['calledOff'] || true === $occurrence->cancelled;

        return $signature;
    }

    /**
     * The five fields, from an event and a pair of instants.
     *
     * Times are compared as timestamps rather than as objects: two
     * DateTimeImmutable instances for the same instant in different zones are
     * the same moment and must not read as a disagreement, and `==` on them
     * would be a value comparison including the zone.
     *
     * @return array<string,mixed>
     */
    private function signatureOf(
        ?CalendarEvent     $event,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
    ): array {
        return [
            'startsAt'  => $startsAt?->getTimestamp(),
            'endsAt'    => $endsAt?->getTimestamp(),
            'title'     => (string) $event?->title,
            'isAllDay'  => true === $event?->isAllDay,
            'calledOff' => EventStatus::Cancelled === $event?->status,
        ];
    }
}
