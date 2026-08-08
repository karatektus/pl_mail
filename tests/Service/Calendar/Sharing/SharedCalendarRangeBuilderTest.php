<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sharing;

use App\Domain\DTO\Calendar\SharedCalendarRange;
use App\Domain\DTO\Calendar\SharedCalendarView;
use App\Domain\DTO\Calendar\SharedOccurrence;
use App\Domain\Enum\Calendar\CalendarView;
use App\Service\Calendar\DayGridLayout;
use App\Service\Calendar\Sharing\SharedCalendarRangeBuilder;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Turning a published window into one page of one view, without inventing days
 * it does not publish.
 *
 * The builder reads nothing and owns no redaction, so what is worth asserting
 * here is entirely arithmetic — and it is exactly the arithmetic that is wrong
 * one day a year when a template does it: six weeks from the right Monday, seven
 * columns from the right one, the window's edge in the owner's zone rather than
 * the server's, and a date off a public URL that cannot walk out of the window.
 *
 * That last one is the reason this file exists rather than being covered by the
 * controller test. The date is hand-editable, and a builder that answered an
 * empty February for a fortnight link in August would put a page on the internet
 * saying its owner is free all February. Nothing about that failure is visible in
 * a render test that only ever asks for the default page.
 *
 * It grew out of SharedCalendarMonthBuilderTest, whose assertions about the month
 * are kept unchanged in meaning — only the anchor is spelled as a date now,
 * because all four views share one URL shape.
 *
 * A plain TestCase: there is no container, no database and no clock to boot.
 */
final class SharedCalendarRangeBuilderTest extends TestCase
{
    private const string ZONE = 'Europe/Berlin';

    /** Six weeks. The month grid's whole point is that this never changes. */
    private const int CELLS = 42;

    /** A Monday, and the first day of the fixture window used below. */
    private const string MONDAY = '2026-08-10';

    // ── The month, which this page had before it had any others ───────────────

    public function testAMonthIsAlwaysSixWeeksFromAMonday(): void
    {
        // August 2026 begins on a Saturday, so the grid has to reach back into
        // July — the case a naive "first of the month" walk gets wrong.
        $page = $this->build($this->window('2026-08-01', '2026-08-31'), CalendarView::Month, '2026-08-01');

        self::assertCount(self::CELLS, $page->days);
        self::assertSame('2026-07-27', $page->days[0]->date);
        self::assertSame('2026-09-06', $page->days[self::CELLS - 1]->date);
        self::assertSame(1, (int) new DateTimeImmutable($page->days[0]->date)->format('N'));
    }

    /** A month that already starts on a Monday is not dragged back a week. */
    public function testAMonthStartingOnAMondayStartsThere(): void
    {
        // June 2026 begins on a Monday.
        $page = $this->build($this->window('2026-06-01', '2026-06-30'), CalendarView::Month, '2026-06-15');

        self::assertSame('2026-06-01', $page->days[0]->date);
    }

    /**
     * The load-bearing distinction: a day the link does not cover is absent
     * from the published map, not present and empty. The shells draw the two
     * differently and cannot if the data does not separate them.
     */
    public function testDaysOutsideTheWindowAreAbsentRatherThanEmpty(): void
    {
        $page = $this->build($this->window('2026-08-10', '2026-08-14'), CalendarView::Month, '2026-08-01');

        $published = $page->gridDays();

        self::assertArrayHasKey('2026-08-10', $published);
        self::assertArrayHasKey('2026-08-14', $published);
        self::assertArrayNotHasKey('2026-08-09', $published, 'the day before the window was published as free');
        self::assertArrayNotHasKey('2026-08-15', $published, 'the day after the window was published as free');
        self::assertCount(5, $published);
    }

    /**
     * The window's last day is the day before its exclusive end. Off by one
     * here publishes a day the owner did not.
     */
    public function testTheExclusiveEndOfTheWindowIsNotPublished(): void
    {
        // 10th to 12th inclusive, which ShareLinkReader spells as an exclusive
        // end of midnight on the 13th.
        $page = $this->build($this->window('2026-08-10', '2026-08-12'), CalendarView::Month, '2026-08-01');

        self::assertArrayHasKey('2026-08-12', $page->gridDays());
        self::assertArrayNotHasKey('2026-08-13', $page->gridDays());
    }

    /** The agenda takes this month's shared days, not the spill-in ones. */
    public function testTheAgendaSkipsSpillInDaysSoNothingIsPrintedTwice(): void
    {
        // A window straddling the month boundary: 28 July to 3 August.
        $page = $this->build($this->window('2026-07-28', '2026-08-03'), CalendarView::Month, '2026-08-01');

        $dates = array_map(static fn ($day): string => $day->date, $page->sharedDays());

        self::assertSame(['2026-08-01', '2026-08-02', '2026-08-03'], $dates);
    }

    public function testMonthPagingStopsAtTheEndsOfTheWindow(): void
    {
        $window = $this->window('2026-07-28', '2026-09-03');

        self::assertNull($this->build($window, CalendarView::Month, '2026-07-29')->previous);
        self::assertSame('2026-08-01', $this->build($window, CalendarView::Month, '2026-07-29')->next);

        self::assertSame('2026-07-01', $this->build($window, CalendarView::Month, '2026-08-15')->previous);
        self::assertSame('2026-09-01', $this->build($window, CalendarView::Month, '2026-08-15')->next);

        self::assertNull($this->build($window, CalendarView::Month, '2026-09-02')->next);
    }

    /**
     * A window inside one month offers no steps at all rather than empty ones.
     *
     * The spill-in cells are why this is not a trivial overlap test: July's grid
     * DOES reach into August, so a check for "any published cell" would offer
     * July here and draw a page whose own month publishes nothing.
     */
    public function testAOneMonthWindowOffersNoMonthPaging(): void
    {
        $page = $this->build($this->window('2026-08-07', '2026-08-21'), CalendarView::Month, null);

        self::assertNull($page->previous);
        self::assertNull($page->next);
    }

    /**
     * A hand-edited date is clamped, not answered. An empty February for a
     * fortnight link in August would read as "free all February".
     */
    public function testADateOutsideTheWindowIsClampedToTheNearestOneInIt(): void
    {
        $window = $this->window('2026-08-10', '2026-08-20');

        self::assertSame('2026-08', $this->build($window, CalendarView::Month, '1999-01-01')->anchor->format('Y-m'));
        self::assertSame('2026-08', $this->build($window, CalendarView::Month, '2099-12-31')->anchor->format('Y-m'));
        self::assertSame('2026-08', $this->build($window, CalendarView::Month, 'not-a-date')->anchor->format('Y-m'));

        // A date that parses as a string and not as a day. Handed to the
        // constructor rather than to createFromFormat this would have been 3
        // March, silently.
        self::assertSame(
            self::MONDAY,
            $this->build($window, CalendarView::Day, '2026-02-31')->anchor->format('Y-m-d'),
        );
    }

    /**
     * With no date asked for, the page opens on the one holding today when the
     * window contains it, and on the window's first day when it does not — a
     * link that starts next quarter should not open on an empty page.
     */
    public function testTheDefaultPageIsTodaysWhenTheWindowHasIt(): void
    {
        self::assertSame(
            '2026-08',
            $this->build($this->window('2026-07-20', '2026-09-10'), CalendarView::Month, null)
                ->anchor->format('Y-m'),
        );

        self::assertSame(
            '2026-11',
            $this->build($this->window('2026-11-02', '2026-11-06'), CalendarView::Month, null)
                ->anchor->format('Y-m'),
        );
    }

    /** The entries land in the cell they belong to and nowhere else. */
    public function testAnEntryLandsInItsOwnCell(): void
    {
        $entry = $this->entry('2026-08-11 10:00', '2026-08-11 11:00');

        $page = $this->build(
            $this->window('2026-08-10', '2026-08-14', ['2026-08-11' => [$entry]]),
            CalendarView::Month,
            '2026-08-01',
        );

        self::assertSame([$entry], $page->gridDays()['2026-08-11']);
        self::assertSame([], $page->gridDays()['2026-08-10']);
        self::assertFalse($page->isEmpty());
    }

    // ── The three views the page did not used to have ─────────────────────────

    /**
     * Seven columns from the anchor's own Monday, and only the ones the window
     * covers are placed — an unplaced column is what the shell draws as "not
     * shared" rather than as twenty-four free hours.
     */
    public function testAWeekIsSevenColumnsAndOnlyTheSharedOnesArePlaced(): void
    {
        // The window is Mon 10 to Fri 14, so the Saturday and Sunday of the same
        // week are drawn and unpublished.
        $page = $this->build($this->window(self::MONDAY, '2026-08-14'), CalendarView::Week, '2026-08-12');

        self::assertSame(self::MONDAY, $page->anchor->format('Y-m-d'), 'a week is anchored on its own Monday');
        self::assertCount(7, $page->days);
        self::assertSame(self::MONDAY, $page->days[0]->date);
        self::assertSame('2026-08-16', $page->days[6]->date);

        self::assertSame(
            [self::MONDAY, '2026-08-11', '2026-08-12', '2026-08-13', '2026-08-14'],
            array_keys($page->grid),
            'the weekend the link does not cover was given hour lines to read an availability off',
        );
    }

    /** Two anchors in one week are one page, or "next" would overlap what it left. */
    public function testEveryDayOfAWeekAnchorsTheSamePage(): void
    {
        $window = $this->window(self::MONDAY, '2026-08-14');

        self::assertSame(
            $this->build($window, CalendarView::Week, self::MONDAY)->days[0]->date,
            $this->build($window, CalendarView::Week, '2026-08-13')->days[0]->date,
        );
    }

    public function testADayViewDrawsOneColumnAndPlacesItOnTheTimeAxis(): void
    {
        $page = $this->build(
            $this->window(self::MONDAY, '2026-08-14', [
                '2026-08-11' => [$this->entry('2026-08-11 09:00', '2026-08-11 10:30')],
            ]),
            CalendarView::Day,
            '2026-08-11',
        );

        self::assertCount(1, $page->days);
        self::assertSame('2026-08-11', $page->days[0]->date);

        $placed = $page->grid['2026-08-11']->timed;

        self::assertCount(1, $placed);
        self::assertEqualsWithDelta(540 / 1440, $placed[0]->top, 0.0001);
        self::assertEqualsWithDelta(90 / 1440, $placed[0]->height, 0.0001);
    }

    /**
     * An entry running past midnight is on BOTH columns of a grid, unlike in the
     * agenda where it is printed once on the day it began.
     *
     * A published column that drew nothing at 00:30 would be this page saying its
     * owner is free at an hour they are not, which is the one thing it must never
     * say untruthfully. ShareLinkReader files the entry under its start day only,
     * so the spreading is the builder's job.
     */
    public function testAMidnightCrossingEntryIsOnBothColumnsOfAGrid(): void
    {
        $window = $this->window(self::MONDAY, '2026-08-14', [
            '2026-08-11' => [$this->entry('2026-08-11 23:00', '2026-08-12 01:00')],
        ]);

        $week = $this->build($window, CalendarView::Week, self::MONDAY);

        self::assertCount(1, $week->grid['2026-08-11']->timed);
        self::assertTrue($week->grid['2026-08-11']->timed[0]->continuesAfter);

        self::assertCount(1, $week->grid['2026-08-12']->timed, 'the small hours of the next day were drawn free');
        self::assertTrue($week->grid['2026-08-12']->timed[0]->continuesBefore);
        self::assertSame(0.0, $week->grid['2026-08-12']->timed[0]->top);

        // And the agenda still lists it once, on the day it started.
        $agenda = $this->build($window, CalendarView::Agenda, self::MONDAY);

        self::assertSame([], $agenda->gridDays()['2026-08-12']);
    }

    /**
     * A long entry costs a page its own seven columns, not the entry's length.
     *
     * The reader hands over every occurrence that OVERLAPS the window, so a
     * multi-year all-day event is one legitimate entry — and the day-spread above
     * used to walk it a day at a time from its own start, which for two thousand
     * such entries is millions of date additions on a public GET. Asserted by
     * what it draws rather than by counting the arithmetic: the entry is on every
     * column of the page and on no day outside it.
     */
    public function testALongEntryIsSpreadOverThePageAndNoFurther(): void
    {
        $entry = new SharedOccurrence(
            new DateTimeImmutable('2020-01-01 00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2030-01-01 00:00', new DateTimeZone('UTC')),
            true,
            'uid@plmail.share',
        );

        $page = $this->build(
            $this->window(self::MONDAY, '2026-08-14', ['2026-08-11' => [$entry]]),
            CalendarView::Week,
            self::MONDAY,
        );

        // Five published columns, each carrying it once, and nothing anywhere
        // else — the weekend the link does not cover has no column to be on.
        self::assertCount(5, $page->grid);

        foreach ($page->grid as $day) {
            self::assertCount(1, $day->allDay);
            self::assertSame([], $day->timed, 'an all-day entry was put on the time axis');
        }
    }

    public function testWeekAndDayPagingStopAtTheWindowEdges(): void
    {
        $window = $this->window(self::MONDAY, '2026-08-14');

        $week = $this->build($window, CalendarView::Week, '2026-08-12');
        self::assertNull($week->previous, 'the week before a Monday-start window was offered');
        self::assertNull($week->next);

        $first = $this->build($window, CalendarView::Day, self::MONDAY);
        self::assertNull($first->previous);
        self::assertSame('2026-08-11', $first->next);

        $last = $this->build($window, CalendarView::Day, '2026-08-14');
        self::assertSame('2026-08-13', $last->previous);
        self::assertNull($last->next);
    }

    /**
     * The agenda lists the shared days of its range and pages by the range
     * rather than by a day: a bounded window paged one day at a time is a
     * fortnight of near-identical pages.
     */
    public function testTheAgendaListsOnlySharedDaysAndPagesByThePage(): void
    {
        $page = $this->build($this->window(self::MONDAY, '2026-08-14'), CalendarView::Agenda, self::MONDAY);

        self::assertCount(30, $page->days, 'the agenda draws its whole range so the unshared days can be left out');
        self::assertCount(5, $page->sharedDays());
        self::assertSame([], $page->grid, 'an agenda has no time axis to position anything on');

        self::assertNull($page->previous);
        self::assertNull($page->next);
    }

    /**
     * And a window long enough for several agenda pages offers them, thirty days
     * at a time.
     *
     * Written after the step was built by sprintf as "-1 30 days" — a string PHP
     * accepts and does not mean, which silently answered null for every backward
     * step and made a three-month link look like a one-page one. A fortnight
     * fixture cannot catch it, because a fortnight has only one page either way.
     */
    public function testTheAgendaOffersThePagesAWindowActuallyHas(): void
    {
        $window = $this->window('2026-08-01', '2026-10-31');

        $middle = $this->build($window, CalendarView::Agenda, '2026-09-01');

        self::assertSame('2026-08-02', $middle->previous);
        self::assertSame('2026-10-01', $middle->next);

        self::assertNull($this->build($window, CalendarView::Agenda, '2026-10-20')->next);
    }

    /**
     * A "today" button with nowhere to go is a control that lies about what it
     * does, so the toolbar is told when there is nowhere.
     */
    public function testTodayIsAnsweredOnlyWhenTheWindowContainsIt(): void
    {
        // The fixture clock is 7 August 2026.
        self::assertSame(
            '2026-08-07',
            $this->build($this->window('2026-08-01', '2026-08-31'), CalendarView::Week, null)->today,
        );

        self::assertNull($this->build($this->window('2026-11-02', '2026-11-06'), CalendarView::Week, null)->today);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function build(SharedCalendarView $window, CalendarView $view, ?string $date): SharedCalendarRange
    {
        return new SharedCalendarRangeBuilder(new DayGridLayout())->build(
            $window,
            $view,
            $date,
            new DateTimeImmutable('2026-08-07 09:00', new DateTimeZone(self::ZONE)),
        );
    }

    private function entry(string $from, string $to): SharedOccurrence
    {
        $zone = new DateTimeZone(self::ZONE);

        return new SharedOccurrence(
            new DateTimeImmutable($from, $zone),
            new DateTimeImmutable($to, $zone),
            false,
            'uid@plmail.share',
        );
    }

    /**
     * A view over an inclusive pair of dates, shaped exactly as ShareLinkReader
     * shapes one: local midnight at the start, local midnight the morning after
     * the last day at the end, and every day in between present.
     *
     * @param array<string, list<SharedOccurrence>> $entries
     */
    private function window(string $from, string $to, array $entries = []): SharedCalendarView
    {
        $zone   = new DateTimeZone(self::ZONE);
        $start  = new DateTimeImmutable($from . ' 00:00:00', $zone);
        $end    = new DateTimeImmutable($to . ' 00:00:00', $zone)->modify('+1 day');
        $days   = [];
        $cursor = $start;

        while ($cursor < $end) {
            $key        = $cursor->format('Y-m-d');
            $days[$key] = $entries[$key] ?? [];
            $cursor     = $cursor->modify('+1 day');
        }

        return new SharedCalendarView($start, $end, self::ZONE, $days, true);
    }
}
