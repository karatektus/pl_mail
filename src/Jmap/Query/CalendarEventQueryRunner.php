<?php

declare(strict_types=1);

namespace App\Jmap\Query;

use App\Entity\User\User;
use App\Jmap\Protocol\Exception\MethodException;
use App\Repository\Calendar\CalendarEventOccurrenceRepository;
use App\Repository\Calendar\CalendarRepository;

/**
 * Runs CalendarEvent/query: which calendars, which window, and the translation
 * from what the index answers to what the client is told.
 *
 * The read is `CalendarEventOccurrenceRepository::findInRange()` — a `tsrange
 * &&` overlap against the GiST index on `calendar_event_occurrence`, which is
 * the same query every calendar view makes. Not a second query written here:
 * the repository's docblock explains why the naive `starts_at < :to AND ends_at
 * > :from` degrades once multi-day events exist, and a JMAP client asking the
 * same question deserves the same index rather than a rediscovery of that.
 *
 * **The result of that read is occurrence rows, and the ids published are event
 * ids.** That translation is the one place this can go wrong silently — both
 * are autoincrement ints, so an untranslated occurrence id names a real, wrong
 * event — so it happens here, once, and CalendarEventMapper's docblock carries
 * the argument for the id space it lands in.
 *
 * Two deliberate absences:
 *
 * **The window is required.** An unbounded query cannot be answered from this
 * index at all: occurrences are materialised only to
 * RecurrenceMaterialiser's horizon, so "everything" would come back looking
 * complete while stopping two years out, and a client cannot detect a truncation
 * nobody reported. Refusing is the only answer that does not lie.
 *
 * **No FilterOperator.** AND/OR/NOT over a range overlap would have to be
 * evaluated outside the index, which is the sequential scan the index exists to
 * avoid; and an OR of two windows is two queries a client can make. Refused by
 * name rather than ignored, for the reason EmailFilterCompiler refuses an
 * unknown condition: a filter quietly dropped returns too much, and the client
 * has no way to tell.
 */
final class CalendarEventQueryRunner
{
    public function __construct(
        private readonly CalendarRepository $calendars,
        private readonly CalendarEventOccurrenceRepository $occurrences,
    ) {
    }

    /**
     * @param array<string,mixed>|null $filter
     */
    public function run(User $user, ?array $filter, int $position, int $limit): CalendarEventQueryResult
    {
        if (null === $filter || 0 === count($filter)) {
            throw new MethodException(
                'unsupportedFilter',
                'CalendarEvent/query needs "after" and "before": occurrences are materialised only to a horizon, so an unbounded query would look complete while stopping at it.',
            );
        }

        if (true === array_key_exists('operator', $filter)) {
            throw new MethodException('unsupportedFilter', 'A FilterOperator is not supported; every condition in a CalendarEvent/query filter is ANDed.');
        }

        foreach (array_keys($filter) as $property) {
            if (false === in_array($property, ['inCalendar', 'after', 'before'], true)) {
                throw new MethodException('unsupportedFilter', sprintf('Filter condition "%s" is not supported.', $property));
            }
        }

        $after = $this->utcDate($filter['after'] ?? null, 'after');
        $before = $this->utcDate($filter['before'] ?? null, 'before');

        $calendarIds = $this->calendarIds($user, $filter['inCalendar'] ?? null);

        $ids = [];

        // findInRange() answers nothing for an empty calendar list, but it is
        // asked explicitly here: an "inCalendar" naming a calendar this user
        // does not own must select no events rather than every event, and
        // reaching the repository to discover that would depend on a contract
        // this method does not own.
        if ([] !== $calendarIds) {
            foreach ($this->occurrences->findInRange($user, $calendarIds, $after, $before) as $occurrence) {
                $eventId = $occurrence->event?->id;

                if (null === $eventId) {
                    continue;
                }

                // Ordered by the occurrence's start, so a series is placed by
                // its FIRST instance inside the window — which is where a
                // client drawing an agenda would expect to meet it — and
                // de-duplicated by keying on the id rather than appending: a
                // weekly meeting overlaps a month-long window four times and
                // is one event.
                $ids[(string) $eventId] = true;
            }
        }

        $ids = array_keys($ids);
        $total = count($ids);

        return new CalendarEventQueryResult(
            $position,
            array_map('strval', array_slice($ids, $position, $limit)),
            $total,
        );
    }

    /**
     * The calendars to search, as ids, always scoped to the user's own.
     *
     * An unowned or unknown "inCalendar" yields an empty list and therefore an
     * empty result — invisible rather than an error, which is the same answer
     * every other lookup here gives for somebody else's row. Reporting
     * "notFound" would confirm that a calendar with that id exists.
     *
     * @return list<int>
     */
    private function calendarIds(User $user, mixed $inCalendar): array
    {
        if (null === $inCalendar) {
            $ids = [];

            foreach ($this->calendars->findForUser($user) as $calendar) {
                $ids[] = (int) $calendar->id;
            }

            return $ids;
        }

        if (false === is_string($inCalendar) && false === is_int($inCalendar)) {
            throw new MethodException('invalidArguments', '"inCalendar" must be a Calendar id.');
        }

        $id = (string) $inCalendar;

        if (false === ctype_digit($id)) {
            throw new MethodException('invalidArguments', '"inCalendar" must be a Calendar id.');
        }

        $calendar = $this->calendars->findOneForUser($user, (int) $id);

        if (null === $calendar) {
            return [];
        }

        return [(int) $calendar->id];
    }

    private function utcDate(mixed $value, string $property): \DateTimeImmutable
    {
        if (false === is_string($value) || '' === $value) {
            throw new MethodException('invalidArguments', sprintf('"%s" must be a UTCDate string.', $property));
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new MethodException('invalidArguments', sprintf('"%s" is not a valid UTCDate.', $value));
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}
