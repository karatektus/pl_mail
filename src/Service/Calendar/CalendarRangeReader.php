<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\DTO\Calendar\DayGrid;
use App\Domain\DTO\Calendar\OccurrenceCluster;
use App\Domain\Enum\Calendar\CalendarView;
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
 *
 * What a day holds is a list of CLUSTERS, not of occurrences. One meeting can
 * legitimately be two rows — extracted from its invitation onto one calendar,
 * mirrored from the provider onto another, both carrying the organiser's UID —
 * and drawing it twice is a lie about the day. The merge is a read-time
 * grouping and nothing else: no column, no second identity, nothing the sync
 * engine can trip over. A lone occurrence is a cluster of one, so the views
 * keep one code path rather than branching. EventClusterer owns what "the same
 * meeting" means; this class owns only which day it lands on.
 *
 * Merged BEFORE the day walk rather than after it, because a cluster spanning
 * midnight has to be placed once as a cluster — grouping the members
 * separately and merging per day would draw the same meeting twice on the days
 * only one member's row happened to touch.
 *
 * `grid` is the same days again, positioned for a time-grid, and it is produced
 * here rather than in the template because it needs the zone this class already
 * resolved — a second resolution of "which clock is this calendar read in"
 * would be a second thing to be wrong on the day they disagreed. `zone` is
 * published for the same reason: a view that prints an hour label has to print
 * it on the clock the placements were computed against, and Twig's date filter
 * defaults to the server's zone rather than to this one.
 *
 * The placement is gated on CalendarView::isTimeGrid() and not on who is
 * asking. The docked pane renders week and day too and does not use it, and
 * that waste is accepted: it is one pass over occurrences that have already
 * been loaded, and the alternative is threading the caller's chrome down into a
 * reader that has no business knowing about it. Month is gated out because it
 * is six weeks of forty-two days that no view will ever position.
 */
final readonly class CalendarRangeReader
{
    public function __construct(
        private CalendarRepository                $calendars,
        private CalendarEventOccurrenceRepository $occurrences,
        private EventClusterer                    $clusterer,
        private DayGridLayout                     $layout,
        private CalendarTimeResolver              $time,
    ) {
    }

    /**
     * @return array{
     *     from: DateTimeImmutable,
     *     to: DateTimeImmutable,
     *     zone: string,
     *     days: array<string, list<OccurrenceCluster>>,
     *     grid: array<string, DayGrid>,
     *     clusters: list<OccurrenceCluster>,
     * }
     */
    public function read(User $user, CalendarView $view, DateTimeImmutable $anchor): array
    {
        $zone = $this->zoneOf($user);

        [$from, $to] = $view->range($anchor->setTimezone($zone));

        $calendarIds = [];

        foreach ($this->calendars->findVisibleForUser($user) as $calendar) {
            $calendarIds[] = (int) $calendar->id;
        }

        $clusters = $this->clusterer->cluster($this->occurrences->findInRange(
            $user,
            $calendarIds,
            $from->modify('-1 day')->setTimezone(new DateTimeZone('UTC')),
            $to->modify('+1 day')->setTimezone(new DateTimeZone('UTC')),
        ));

        $days = $this->groupByLocalDay($clusters, $zone, $from, $to);

        return [
            'from'     => $from,
            'to'       => $to,
            'zone'     => $zone->getName(),
            'days'     => $days,
            'grid'     => true === $view->isTimeGrid() ? $this->layout->place($days, $zone) : [],
            'clusters' => $clusters,
        ];
    }

    /**
     * Keyed Y-m-d in the user's zone, every day in the window present even when
     * empty — a month grid needs its blank cells, and building them in Twig
     * means repeating the date walk in every view.
     *
     * A cluster is placed by its primary's span, which is the whole cluster's:
     * members that disagree about when they are have already been split apart,
     * so there is no second answer to choose between here.
     *
     * @param list<OccurrenceCluster> $clusters
     *
     * @return array<string, list<OccurrenceCluster>>
     */
    private function groupByLocalDay(
        array             $clusters,
        DateTimeZone      $zone,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $days = [];

        for ($day = $from; $day < $to; $day = $day->modify('+1 day')) {
            $days[$day->format('Y-m-d')] = [];
        }

        foreach ($clusters as $cluster) {
            // An all-day event is FLOATING — its columns hold a wall date at
            // midnight and no zone, which is what "the same day everywhere"
            // means and how RecurrenceMaterialiser expands it. Converting one
            // into a real zone does not translate it, it moves it: midnight UTC
            // read as an instant becomes 02:00 in Berlin, so the event ran from
            // 02:00 on its day to 02:00 on the next and the walk below filed it
            // under BOTH. West of UTC it moved onto the day before instead.
            // Left alone, its own zone is UTC and its wall date is the key.
            $isFloating = true === $cluster->primary->event?->isAllDay;

            $start = $isFloating ? $cluster->primary->startsAt : $cluster->primary->startsAt->setTimezone($zone);
            $end   = $isFloating ? $cluster->primary->endsAt : $cluster->primary->endsAt->setTimezone($zone);

            // A multi-day event belongs to every day it touches, not only the
            // one it started on — otherwise it vanishes from the week whose
            // Monday it began before.
            for ($day = $start->setTime(0, 0); $day < $end; $day = $day->modify('+1 day')) {
                $key = $day->format('Y-m-d');

                if (true === array_key_exists($key, $days)) {
                    $days[$key][] = $cluster;
                }
            }
        }

        return $days;
    }

    /**
     * The clock this view is drawn on, and it is CalendarTimeResolver's answer
     * rather than a second one computed here.
     *
     * This method used to repeat that resolution — default calendar's zone, else
     * `date_default_timezone_get()` — and the repetition was the bug. PHP's
     * default is pinned to UTC in this container, so a user with no default
     * calendar had the grid positioned in UTC while the gutter beside it is
     * labelled 00:00–23:00 by hour index and therefore looks local: the
     * current-time line sat two hours off its own labels for a Berlin reader,
     * and every block was placed against the same wrong clock. One resolver
     * means the grid, the chips printed on it (see _event_chip's `chip_zone`)
     * and the editor a click opens cannot disagree.
     */
    private function zoneOf(User $user): DateTimeZone
    {
        return $this->time->zoneFor($user);
    }
}
