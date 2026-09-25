<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\DTO\Calendar\OccurrenceCluster;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Service\Calendar\DayGridLayout;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * A time-grid says where an event is by drawing it there, so the arithmetic
 * that decides where "there" is has to be right about the awkward cases.
 *
 * Everything this class gets wrong is invisible: a block is drawn, it is just
 * drawn at the wrong hour, or on top of another one, or with a height that says
 * a meeting is twice as long as it is. Nothing throws and nothing looks broken,
 * which is why each of these exists:
 *
 *   An event finishing exactly at midnight reads its end as 00:00, and a naive
 *   implementation gives it a height running from its start back up to the top
 *   of the column — a block drawn upside down over the whole day.
 *
 *   An event that started yesterday has a start before this column exists.
 *   Placed at its own hour it lands on tomorrow morning; clipped without saying
 *   so it is indistinguishable from one that genuinely began at midnight.
 *
 *   Two events at the same time, sized independently, produce a half-width
 *   block beside a third-width one, which reads as a rendering fault. Lanes are
 *   only worth anything if everything in an overlapping run agrees how many
 *   there are.
 *
 *   An all-day event has no time to be positioned at. Left in the timed list it
 *   is a zero-height block on the midnight line: invisible, and a claim that
 *   "all day" means "at 00:00".
 *
 * A plain TestCase, and the only test in this directory that is: this class
 * takes no collaborators and touches no database — it is arithmetic over
 * clusters, and giving it a kernel and a transaction would buy nothing but a
 * slower suite. The occurrences are built by hand for the same reason.
 */
final class DayGridLayoutTest extends TestCase
{
    private const string ZONE = 'Europe/Berlin';
    private const string DAY  = '2026-08-05';

    private DayGridLayout $layout;

    protected function setUp(): void
    {
        $this->layout = new DayGridLayout();
    }

    public function testAnEventIsPlacedAtItsOwnHourAndIsAsTallAsItIsLong(): void
    {
        $grid = $this->place([$this->timed('09:00', '10:30')]);

        $placed = $grid[self::DAY]->timed[0];

        // 09:00 is 540 minutes into 1440, and ninety minutes is 90 of them.
        self::assertEqualsWithDelta(540 / 1440, $placed->top, 0.0001);
        self::assertEqualsWithDelta(90 / 1440, $placed->height, 0.0001);
    }

    /**
     * `>=` on the end and `>` on the "continues" flag, and the difference is
     * the whole of this case: minuteOf() reads 00:00 for an end at midnight, so
     * an implementation that positions on it alone gives the block a negative
     * height and draws it upwards over the evening.
     */
    public function testAnEventEndingAtMidnightRunsToTheBottomRatherThanBackToTheTop(): void
    {
        $grid = $this->place([$this->timed('22:00', '2026-08-06 00:00')]);

        $placed = $grid[self::DAY]->timed[0];

        self::assertEqualsWithDelta(1320 / 1440, $placed->top, 0.0001);
        self::assertEqualsWithDelta(120 / 1440, $placed->height, 0.0001);
        self::assertFalse($placed->continuesAfter, 'midnight is where this day ends, not where the next one starts');
    }

    public function testAnEventCrossingMidnightIsClippedToTheDayAndSaysSo(): void
    {
        $grid = $this->place([$this->timed('23:00', '2026-08-06 01:00')]);

        $placed = $grid[self::DAY]->timed[0];

        self::assertEqualsWithDelta(1380 / 1440, $placed->top, 0.0001);
        self::assertEqualsWithDelta(60 / 1440, $placed->height, 0.0001);
        self::assertTrue($placed->continuesAfter);
        self::assertFalse($placed->continuesBefore);
    }

    /**
     * The other end of the same event, on the day it finishes. Without the
     * clamp it would be positioned at 23:00 on the wrong day; without the flag
     * it would be indistinguishable from a meeting that starts at midnight.
     */
    public function testAnEventThatBeganYesterdayStartsAtTheTopOfTodayAndSaysSo(): void
    {
        $grid = $this->place([$this->timed('2026-08-04 23:00', '01:00')]);

        $placed = $grid[self::DAY]->timed[0];

        self::assertSame(0.0, $placed->top);
        self::assertEqualsWithDelta(60 / 1440, $placed->height, 0.0001);
        self::assertTrue($placed->continuesBefore);
    }

    public function testAnAllDayEventIsLiftedOutRatherThanDrawnAtMidnight(): void
    {
        $grid = $this->place([$this->timed('00:00', '2026-08-06 00:00', allDay: true)]);

        self::assertCount(1, $grid[self::DAY]->allDay);
        self::assertSame([], $grid[self::DAY]->timed, 'nothing all-day belongs on the time axis');
    }

    /**
     * A Friday-evening-to-Sunday trip, as it was reported: three blocks down
     * three columns, each lifting on its own under the pointer, and the
     * weekend's other meetings squeezed into half a lane beside a column-high
     * block. A timed entry of a day or more is shaded on each day it takes
     * instead, and takes no lane from anything.
     */
    public function testAnEntryOfADayOrMoreIsShadedOnEachDayAndTakesNoLane(): void
    {
        $trip  = $this->timed('2026-08-07 17:00', '2026-08-09 21:00');
        $lunch = $this->timed('2026-08-08 12:00', '2026-08-08 13:00');

        $grid = $this->layout->place([
            '2026-08-07' => [$trip],
            '2026-08-08' => [$trip, $lunch],
            '2026-08-09' => [$trip],
        ], new DateTimeZone(self::ZONE));

        $friday = $grid['2026-08-07']->shaded[0];
        self::assertEqualsWithDelta(17 / 24, $friday->top, 0.0001);
        self::assertEqualsWithDelta(7 / 24, $friday->height, 0.0001);
        self::assertFalse($friday->continuesBefore, 'the trip starts here, and the start is ruled');
        self::assertTrue($friday->continuesAfter);

        self::assertSame(1.0, $grid['2026-08-08']->shaded[0]->height);
        self::assertEqualsWithDelta(21 / 24, $grid['2026-08-09']->shaded[0]->height, 0.0001);

        self::assertSame([], $grid['2026-08-07']->timed, 'the trip was drawn as a block');
        self::assertCount(1, $grid['2026-08-08']->timed);
        self::assertSame(1, $grid['2026-08-08']->timed[0]->lanes, 'lunch was squeezed beside the trip');
    }

    /**
     * The trip's other half: one bar across its three days, in the band's top
     * row, carrying the key its shaded hours carry — which is what lets pointing
     * at either light both. An all-day entry on the Saturday takes the row below
     * rather than overlapping it.
     */
    public function testTheBandDrawsAnEntryOnceAcrossTheDaysItCovers(): void
    {
        $trip     = $this->timed('2026-08-07 17:00', '2026-08-09 21:00');
        $birthday = $this->timed('2026-08-08 00:00', '2026-08-09 00:00', allDay: true);
        $zone     = new DateTimeZone(self::ZONE);
        $days     = [
            '2026-08-06' => [],
            '2026-08-07' => [$trip],
            '2026-08-08' => [$trip, $birthday],
            '2026-08-09' => [$trip],
        ];

        $band = $this->layout->band($days, $zone);

        self::assertSame(2, $band->lanes);

        [$bar, $below] = $band->entries;

        self::assertSame($trip, $bar->entry);
        self::assertSame('2026-08-07', $bar->firstDay);
        self::assertSame(3, $bar->days);
        self::assertSame(0, $bar->lane);
        self::assertTrue($bar->timed);
        self::assertFalse($bar->continuesBefore);
        self::assertFalse($bar->continuesAfter);
        self::assertSame($this->layout->place($days, $zone)['2026-08-08']->shaded[0]->key, $bar->key);

        self::assertSame($birthday, $below->entry);
        self::assertSame(1, $below->lane);
        self::assertFalse($below->timed);
    }

    /**
     * The line from the other side: a meeting that crosses midnight but lasts
     * under a day is an evening that ran late, and stays on the hours — as two
     * blocks sharing one key, so they light together.
     */
    public function testAMeetingUnderADayStaysOnTheHoursAndItsHalvesShareAKey(): void
    {
        $late = $this->timed('2026-08-05 22:00', '2026-08-06 01:00');
        $zone = new DateTimeZone(self::ZONE);
        $days = ['2026-08-05' => [$late], '2026-08-06' => [$late]];

        $grid = $this->layout->place($days, $zone);

        self::assertSame([], $this->layout->band($days, $zone)->entries);
        self::assertSame([], $grid['2026-08-05']->shaded);
        self::assertSame($grid['2026-08-05']->timed[0]->key, $grid['2026-08-06']->timed[0]->key);
    }

    public function testTwoEventsAtOnceShareTheWidthRatherThanOverlapping(): void
    {
        $grid = $this->place([
            $this->timed('09:00', '10:00'),
            $this->timed('09:30', '10:30'),
        ]);

        $lanes = array_map(static fn ($placed): int => $placed->lane, $grid[self::DAY]->timed);

        self::assertSame([0, 1], $lanes);
        self::assertSame([2, 2], array_map(static fn ($placed): int => $placed->lanes, $grid[self::DAY]->timed));
    }

    /**
     * A chain of three, where only the middle one touches both ends: 09:00–10:00,
     * 09:30–11:30, 11:00–12:00. Nothing here is three-deep, so the run is two
     * lanes wide and the third block goes back into the lane the first one has
     * finished with.
     *
     * Both halves of that matter. Sized pair by pair, the 09:00 and the 11:00
     * would each think themselves alone and be drawn full width beside a middle
     * block at a third of the column, which reads as a rendering fault. Given a
     * lane each instead, the day would be three columns wide to draw two things
     * at once.
     */
    public function testAnOverlappingRunAgreesOnOneWidthAndReusesTheLanesInIt(): void
    {
        $grid = $this->place([
            $this->timed('09:00', '10:00'),
            $this->timed('09:30', '11:30'),
            $this->timed('11:00', '12:00'),
        ]);

        self::assertSame(
            [2, 2, 2],
            array_map(static fn ($placed): int => $placed->lanes, $grid[self::DAY]->timed),
            'one width down the whole run, whatever each member overlaps',
        );

        self::assertSame(
            [0, 1, 0],
            array_map(static fn ($placed): int => $placed->lane, $grid[self::DAY]->timed),
            'the 11:00 takes the lane the 09:00 has vacated',
        );
    }

    /**
     * A lane is reused the moment it is free. Without that, a day of ten
     * back-to-back meetings would be ten lanes wide and each block a tenth of a
     * column — and none of them overlaps anything.
     */
    public function testEventsThatDoNotMeetEachKeepTheWholeWidth(): void
    {
        $grid = $this->place([
            $this->timed('09:00', '10:00'),
            $this->timed('10:00', '11:00'),
        ]);

        self::assertSame(
            [1, 1],
            array_map(static fn ($placed): int => $placed->lanes, $grid[self::DAY]->timed),
            'an event starting when another ends is not concurrent with it',
        );
    }

    /**
     * The width says how many things are happening AT ONCE, so it is a fact
     * about a run of overlapping events and not about the day.
     *
     * A single greedy pass over the whole day gets the lane assignment right
     * and the count wrong: the busiest moment sets one number for everything
     * after it, so a lone afternoon meeting is drawn at half width because two
     * things happened that morning, and the width stops being readable.
     */
    public function testAnEventAloneInTheAfternoonIsFullWidthHoweverBusyTheMorningWas(): void
    {
        $grid = $this->place([
            $this->timed('09:00', '10:00'),
            $this->timed('09:00', '10:00'),
            $this->timed('14:00', '15:00'),
        ]);

        self::assertSame(
            [2, 2, 1],
            array_map(static fn ($placed): int => $placed->lanes, $grid[self::DAY]->timed),
        );
    }

    /**
     * Equal starts put the longer block in lane 0. The reverse leaves the long
     * one in the rightmost lane with a column of short ones down its left,
     * which reads as though the stubs were the main event.
     */
    public function testTheLongerOfTwoEventsStartingTogetherTakesTheFirstLane(): void
    {
        $grid = $this->place([
            $this->timed('09:00', '09:30'),
            $this->timed('09:00', '11:00'),
        ]);

        $first = $grid[self::DAY]->timed[0];

        self::assertSame(0, $first->lane);
        self::assertEqualsWithDelta(120 / 1440, $first->height, 0.0001, 'the two-hour one leads');
    }

    /**
     * Positions are wall-clock minutes, not elapsed time, because the grid draws
     * twenty-four labelled rows and a block has to land against the row whose
     * label matches. Berlin springs forward at 02:00 on 2026-03-29, so an event
     * at 10:00 that day is 23 hours after midnight and 600 minutes down the
     * column — the second of those is the one the labels agree with.
     */
    public function testTheAxisFollowsTheLabelsRatherThanElapsedTimeAcrossADstJump(): void
    {
        $day  = '2026-03-29';
        $grid = $this->layout->place(
            [$day => [$this->cluster($day . ' 10:00', $day . ' 11:00', false)]],
            new DateTimeZone(self::ZONE),
        );

        self::assertEqualsWithDelta(600 / 1440, $grid[$day]->timed[0]->top, 0.0001);
    }

    /** An empty day is still a day; the grid needs its column drawn. */
    public function testADayWithNothingOnItStillGetsAGrid(): void
    {
        $grid = $this->place([]);

        self::assertSame([], $grid[self::DAY]->allDay);
        self::assertSame([], $grid[self::DAY]->timed);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @param list<OccurrenceCluster> $clusters
     *
     * @return array<string, \App\Domain\DTO\Calendar\DayGrid>
     */
    private function place(array $clusters): array
    {
        return $this->layout->place([self::DAY => $clusters], new DateTimeZone(self::ZONE));
    }

    /**
     * A cluster of one, from two wall times in the fixture zone. A bare `H:i`
     * means the fixture day; anything with a date in it means that date, which
     * is how the midnight cases say which side of it they are on.
     */
    private function timed(string $from, string $to, bool $allDay = false): OccurrenceCluster
    {
        return $this->cluster(
            true === str_contains($from, '-') ? $from : self::DAY . ' ' . $from,
            true === str_contains($to, '-') ? $to : self::DAY . ' ' . $to,
            $allDay,
        );
    }

    private function cluster(string $from, string $to, bool $allDay): OccurrenceCluster
    {
        $zone = new DateTimeZone(self::ZONE);

        $event           = new CalendarEvent();
        $event->isAllDay = $allDay;
        $event->title    = 'Fixture';

        $calendar        = new Calendar();
        $calendar->name  = 'Fixture';
        $calendar->color = '#2563eb';

        $occurrence           = new CalendarEventOccurrence();
        $occurrence->event    = $event;
        $occurrence->calendar = $calendar;
        $occurrence->startsAt = new DateTimeImmutable($from, $zone);
        $occurrence->endsAt   = new DateTimeImmutable($to, $zone);

        return OccurrenceCluster::of([$occurrence]);
    }
}
