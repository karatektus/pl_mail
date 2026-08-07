<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sharing;

use App\Domain\DTO\Calendar\SharedCalendarMonth;
use App\Domain\DTO\Calendar\SharedCalendarView;
use App\Domain\DTO\Calendar\SharedOccurrence;
use App\Service\Calendar\Sharing\SharedCalendarMonthBuilder;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Turning a published window into a month grid, without inventing days it does
 * not publish.
 *
 * The builder reads nothing and owns no redaction, so what is worth asserting
 * here is entirely arithmetic — and it is exactly the arithmetic that is wrong
 * one day a year when a template does it: six weeks from the right Monday, the
 * window's edge in the owner's zone rather than the server's, and a `month`
 * parameter off a public URL that cannot walk out of the window.
 *
 * The last one is the reason this file exists rather than being covered by the
 * controller test. `?month=` is hand-editable, and a builder that answered an
 * empty February for a fortnight link in August would put a page on the
 * internet saying its owner is free all February. Nothing about that failure is
 * visible in a render test that only ever asks for the default month.
 *
 * A plain TestCase: there is no container, no database and no clock to boot.
 */
final class SharedCalendarMonthBuilderTest extends TestCase
{
    private const string ZONE = 'Europe/Berlin';

    /** Six weeks. The grid's whole point is that this never changes. */
    private const int CELLS = 42;

    public function testAMonthIsAlwaysSixWeeksFromAMonday(): void
    {
        // August 2026 begins on a Saturday, so the grid has to reach back into
        // July — the case a naive "first of the month" walk gets wrong.
        $month = $this->build($this->window('2026-08-01', '2026-08-31'), '2026-08');

        self::assertCount(self::CELLS, $month->days);
        self::assertSame('2026-07-27', $month->days[0]->date);
        self::assertSame('2026-09-06', $month->days[self::CELLS - 1]->date);
        self::assertSame(1, (int) new DateTimeImmutable($month->days[0]->date)->format('N'));
    }

    /** A month that already starts on a Monday is not dragged back a week. */
    public function testAMonthStartingOnAMondayStartsThere(): void
    {
        // June 2026 begins on a Monday.
        $month = $this->build($this->window('2026-06-01', '2026-06-30'), '2026-06');

        self::assertSame('2026-06-01', $month->days[0]->date);
    }

    /**
     * The load-bearing distinction: a cell the link does not cover is absent
     * from the grid map, not present and empty. The shell draws the two
     * differently and cannot if the data does not separate them.
     */
    public function testDaysOutsideTheWindowAreAbsentRatherThanEmpty(): void
    {
        $month = $this->build($this->window('2026-08-10', '2026-08-14'), '2026-08');

        $grid = $month->gridDays();

        self::assertArrayHasKey('2026-08-10', $grid);
        self::assertArrayHasKey('2026-08-14', $grid);
        self::assertArrayNotHasKey('2026-08-09', $grid, 'the day before the window was published as free');
        self::assertArrayNotHasKey('2026-08-15', $grid, 'the day after the window was published as free');
        self::assertCount(5, $grid);
    }

    /**
     * The window's last day is the day before its exclusive end. Off by one
     * here publishes a day the owner did not.
     */
    public function testTheExclusiveEndOfTheWindowIsNotPublished(): void
    {
        // 10th to 12th inclusive, which ShareLinkReader spells as an exclusive
        // end of midnight on the 13th.
        $month = $this->build($this->window('2026-08-10', '2026-08-12'), '2026-08');

        self::assertArrayHasKey('2026-08-12', $month->gridDays());
        self::assertArrayNotHasKey('2026-08-13', $month->gridDays());
    }

    /** The list under the grid takes this month's shared days, not the spill-in ones. */
    public function testTheDayListSkipsSpillInDaysSoNothingIsPrintedTwice(): void
    {
        // A window straddling the month boundary: 28 July to 3 August.
        $month = $this->build($this->window('2026-07-28', '2026-08-03'), '2026-08');

        $dates = array_map(static fn ($day): string => $day->date, $month->sharedDays());

        self::assertSame(['2026-08-01', '2026-08-02', '2026-08-03'], $dates);
    }

    public function testPagingStopsAtTheEndsOfTheWindow(): void
    {
        $window = $this->window('2026-07-28', '2026-09-03');

        self::assertNull($this->build($window, '2026-07')->previous);
        self::assertSame('2026-08', $this->build($window, '2026-07')->next);

        self::assertSame('2026-07', $this->build($window, '2026-08')->previous);
        self::assertSame('2026-09', $this->build($window, '2026-08')->next);

        self::assertNull($this->build($window, '2026-09')->next);
    }

    /** A window inside one month offers no steps at all rather than empty ones. */
    public function testAOneMonthWindowOffersNoPaging(): void
    {
        $month = $this->build($this->window('2026-08-07', '2026-08-21'), null);

        self::assertNull($month->previous);
        self::assertNull($month->next);
    }

    /**
     * A hand-edited month is clamped, not answered. An empty February for a
     * fortnight link in August would read as "free all February".
     */
    public function testAMonthOutsideTheWindowIsClampedToTheNearestOneInIt(): void
    {
        $window = $this->window('2026-08-10', '2026-08-20');

        self::assertSame('2026-08', $this->build($window, '1999-01')->anchor->format('Y-m'));
        self::assertSame('2026-08', $this->build($window, '2099-12')->anchor->format('Y-m'));
        self::assertSame('2026-08', $this->build($window, 'not-a-month')->anchor->format('Y-m'));
    }

    /**
     * With no month asked for, the grid opens on the one holding today when the
     * window contains it, and on the window's first month when it does not — a
     * link that starts next quarter should not open on an empty page.
     */
    public function testTheDefaultMonthIsTodaysWhenTheWindowHasIt(): void
    {
        $now = new DateTimeImmutable('2026-08-07 09:00', new DateTimeZone(self::ZONE));

        self::assertSame(
            '2026-08',
            new SharedCalendarMonthBuilder()
                ->build($this->window('2026-07-20', '2026-09-10'), null, $now)
                ->anchor->format('Y-m'),
        );

        self::assertSame(
            '2026-11',
            new SharedCalendarMonthBuilder()
                ->build($this->window('2026-11-02', '2026-11-06'), null, $now)
                ->anchor->format('Y-m'),
        );
    }

    /** The entries land in the cell they belong to and nowhere else. */
    public function testAnEntryLandsInItsOwnCell(): void
    {
        $entry = new SharedOccurrence(
            new DateTimeImmutable('2026-08-11 10:00', new DateTimeZone(self::ZONE)),
            new DateTimeImmutable('2026-08-11 11:00', new DateTimeZone(self::ZONE)),
            false,
            'uid@plmail.share',
        );

        $month = $this->build($this->window('2026-08-10', '2026-08-14', ['2026-08-11' => [$entry]]), '2026-08');

        self::assertSame([$entry], $month->gridDays()['2026-08-11']);
        self::assertSame([], $month->gridDays()['2026-08-10']);
        self::assertFalse($month->isEmpty());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function build(SharedCalendarView $view, ?string $month): SharedCalendarMonth
    {
        return new SharedCalendarMonthBuilder()->build(
            $view,
            $month,
            new DateTimeImmutable('2026-08-07 09:00', new DateTimeZone(self::ZONE)),
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
