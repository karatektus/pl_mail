<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Booking;

use App\Domain\DTO\Calendar\BookableSlot;
use App\Domain\DTO\Calendar\BookingWeek;
use App\Service\Calendar\Booking\BookingWeekBuilder;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Cutting a booking page's slots into the week on screen.
 *
 * BookingAvailabilityReaderTest owns which slots exist; nothing here can add
 * one, and the assertions are about the two things a display layer can still
 * get wrong on a public URL.
 *
 * **Where it opens.** A page with a fortnight's notice has nothing this week by
 * construction, so opening on "this week" would show an empty page to everybody
 * who ever visited it. It opens on the week holding the first free slot.
 *
 * **Where it stops.** `w` is hand-editable. Paging past the horizon would draw a
 * week of empty columns, which on this page is indistinguishable from a week the
 * owner has no time in — the one thing the booking feature refuses to say. So
 * the ends have no step beyond them and a nonsense value is clamped rather than
 * answered.
 *
 * A plain TestCase: the builder has no dependencies, which is most of why the
 * grouping was pulled out of the controller.
 */
final class BookingWeekBuilderTest extends TestCase
{
    private const string ZONE = 'Europe/Berlin';

    /** Monday 3 August 2026, which is the Monday of the fixture's first week. */
    private const string FIRST_MONDAY = '2026-08-03';

    public function testAWeekIsSevenDaysFromAMonday(): void
    {
        $week = $this->build(['2026-08-05'], null);

        self::assertCount(7, $week->days);
        self::assertSame(self::FIRST_MONDAY, $week->startsOn->format('Y-m-d'));
        self::assertSame('2026-08-09', $week->days[6]->date);
    }

    /**
     * Every day of the week is drawn, including the ones with nothing free.
     * "Nothing on Wednesday" has to be something the page says rather than a
     * heading the reader notices is missing.
     */
    public function testDaysWithNothingFreeArePresentAndEmpty(): void
    {
        $week = $this->build(['2026-08-05'], null);

        $free = [];

        foreach ($week->days as $day) {
            $free[$day->date] = count($day->slots);
        }

        self::assertSame(
            [
                '2026-08-03' => 0,
                '2026-08-04' => 0,
                '2026-08-05' => 1,
                '2026-08-06' => 0,
                '2026-08-07' => 0,
                '2026-08-08' => 0,
                '2026-08-09' => 0,
            ],
            $free,
        );
    }

    /** The page opens where there is something to book, not on today. */
    public function testItOpensOnTheWeekHoldingTheFirstFreeSlot(): void
    {
        // Nothing until a fortnight out, which is what a long notice period
        // produces and what made the old page open on an empty screen.
        $week = $this->build(['2026-08-25'], null);

        self::assertSame('2026-08-24', $week->startsOn->format('Y-m-d'));
    }

    public function testPagingStopsAtTheFirstAndLastWeekOffered(): void
    {
        $days = ['2026-08-05', '2026-08-12', '2026-08-19'];

        self::assertNull($this->build($days, '2026-08-03')->previous);
        self::assertSame('2026-08-10', $this->build($days, '2026-08-03')->next);

        self::assertSame('2026-08-03', $this->build($days, '2026-08-10')->previous);
        self::assertSame('2026-08-17', $this->build($days, '2026-08-10')->next);

        self::assertNull($this->build($days, '2026-08-17')->next);
    }

    /** A hand-edited week is clamped to one that exists rather than answered empty. */
    public function testAWeekOutsideWhatIsOfferedIsClamped(): void
    {
        $days = ['2026-08-05', '2026-08-12'];

        self::assertSame(self::FIRST_MONDAY, $this->build($days, '1999-01-04')->startsOn->format('Y-m-d'));
        self::assertSame('2026-08-10', $this->build($days, '2099-12-07')->startsOn->format('Y-m-d'));
        self::assertSame(self::FIRST_MONDAY, $this->build($days, 'next tuesday')->startsOn->format('Y-m-d'));
    }

    /** A day already past is marked, so four dead columns do not read as a blocked week. */
    public function testDaysBeforeTodayAreMarkedPastAndTodayIsMarkedToday(): void
    {
        $week = $this->build(['2026-08-07'], null);

        $past  = [];
        $today = [];

        foreach ($week->days as $day) {
            if (true === $day->isPast) {
                $past[] = $day->date;
            }

            if (true === $day->isToday) {
                $today[] = $day->date;
            }
        }

        self::assertSame(['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06'], $past);
        self::assertSame(['2026-08-07'], $today);
    }

    /**
     * A page with nothing free at all still answers a week rather than nothing,
     * and offers no steps — a control that led to more of the same is a control
     * that does nothing.
     */
    public function testAPageWithNothingFreeAnswersThisWeekWithNoSteps(): void
    {
        $week = $this->build([], null);

        self::assertTrue($week->isEmpty());
        self::assertCount(7, $week->days);
        self::assertSame(self::FIRST_MONDAY, $week->startsOn->format('Y-m-d'));
        self::assertNull($week->previous);
        self::assertNull($week->next);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * A week built from a set of days that each hold one slot at ten, as
     * BookingAvailabilityReader would have grouped them — days with nothing free
     * absent rather than empty.
     *
     * @param list<string> $dates
     */
    private function build(array $dates, ?string $week): BookingWeek
    {
        $zone = new DateTimeZone(self::ZONE);
        $days = [];

        foreach ($dates as $date) {
            $start = new DateTimeImmutable($date . ' 10:00:00', $zone);

            $days[$date] = [new BookableSlot($start, $start->modify('+30 minutes'))];
        }

        return new BookingWeekBuilder()->build(
            $days,
            $zone,
            // Friday 7 August 2026, which is what makes the past/today
            // assertions above concrete.
            new DateTimeImmutable('2026-08-07 09:00', $zone),
            $week,
        );
    }
}
