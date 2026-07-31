<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\Enum\Calendar\CalendarView;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventOccurrenceRepository;
use App\Repository\Calendar\CalendarRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads a view's worth of occurrences and groups them by local day.
 *
 * The grouping is here rather than in Twig because it depends on the user's
 * zone, and a template that does date arithmetic is a template that gets it
 * wrong on one day a year. Storage is UTC; a view is a wall clock.
 *
 * The window is widened by a day either side before it is queried. All-day
 * events are stored floating — local midnight, no zone — while timed events
 * are UTC, so a user well east or west of UTC has occurrences that belong to a
 * day the raw window does not quite cover. Padding is cheaper and more obvious
 * than two queries with different boundaries.
 */
final readonly class CalendarRangeReader
{
    public function __construct(
        private CalendarRepository                $calendars,
        private CalendarEventOccurrenceRepository $occurrences,
    ) {
    }

    /**
     * @return array{
     *     from: DateTimeImmutable,
     *     to: DateTimeImmutable,
     *     days: array<string, list<CalendarEventOccurrence>>,
     *     occurrences: list<CalendarEventOccurrence>,
     * }
     */
    public function read(User $user, CalendarView $view, DateTimeImmutable $anchor): array
    {
        $zone = $this->zoneOf($user, $anchor);

        [$from, $to] = $view->range($anchor->setTimezone($zone));

        $calendarIds = [];

        foreach ($this->calendars->findVisibleForUser($user) as $calendar) {
            $calendarIds[] = (int) $calendar->id;
        }

        $occurrences = $this->occurrences->findInRange(
            $user,
            $calendarIds,
            $from->modify('-1 day')->setTimezone(new DateTimeZone('UTC')),
            $to->modify('+1 day')->setTimezone(new DateTimeZone('UTC')),
        );

        return [
            'from'        => $from,
            'to'          => $to,
            'days'        => $this->groupByLocalDay($occurrences, $zone, $from, $to),
            'occurrences' => $occurrences,
        ];
    }

    /**
     * Keyed Y-m-d in the user's zone, every day in the window present even when
     * empty — a month grid needs its blank cells, and building them in Twig
     * means repeating the date walk in every view.
     *
     * @param list<CalendarEventOccurrence> $occurrences
     *
     * @return array<string, list<CalendarEventOccurrence>>
     */
    private function groupByLocalDay(
        array             $occurrences,
        DateTimeZone      $zone,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $days = [];

        for ($day = $from; $day < $to; $day = $day->modify('+1 day')) {
            $days[$day->format('Y-m-d')] = [];
        }

        foreach ($occurrences as $occurrence) {
            $start = $occurrence->startsAt->setTimezone($zone);
            $end   = $occurrence->endsAt->setTimezone($zone);

            // A multi-day event belongs to every day it touches, not only the
            // one it started on — otherwise it vanishes from the week whose
            // Monday it began before.
            for ($day = $start->setTime(0, 0); $day < $end; $day = $day->modify('+1 day')) {
                $key = $day->format('Y-m-d');

                if (true === array_key_exists($key, $days)) {
                    $days[$key][] = $occurrence;
                }
            }
        }

        return $days;
    }

    /**
     * The user's default calendar decides the display zone. Falling back to the
     * server's is right for a self-hosted app: it is the machine the user set
     * up, not an arbitrary datacentre.
     */
    private function zoneOf(User $user, DateTimeImmutable $anchor): DateTimeZone
    {
        $calendar = $this->calendars->findDefaultForUser($user);

        try {
            return new DateTimeZone($calendar->timeZone ?? date_default_timezone_get());
        } catch (\Exception) {
            return $anchor->getTimezone();
        }
    }
}
