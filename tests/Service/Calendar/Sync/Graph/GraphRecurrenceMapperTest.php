<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\Graph;

use App\Service\Calendar\Sync\Graph\GraphRecurrenceMapper;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Graph's six named patterns against JSCalendar's one open grammar.
 *
 * Every case here is one where a plausible translation is wrong rather than
 * merely lossy: an event on the wrong day recurs on the wrong day for years and
 * nobody reports it as a sync bug, they report it as "the calendar is wrong".
 * The two that have actually bitten similar code elsewhere are pinned first —
 * Graph's `last` index, which is not a fifth week, and its `endDate`, which is a
 * date where RRULE wants an instant.
 *
 * The out direction is checked as hard as the in direction because it lands in
 * other people's calendars: a botched pattern invites four colleagues to a
 * meeting on a day nobody chose.
 */
final class GraphRecurrenceMapperTest extends TestCase
{
    public function testAWeeklyPatternKeepsItsDaysAndItsInterval(): void
    {
        $rule = $this->mapper()->toJsCalendar([
            'pattern' => [
                'type'           => 'weekly',
                'interval'       => 2,
                'daysOfWeek'     => ['monday', 'thursday'],
                'firstDayOfWeek' => 'sunday',
            ],
            'range' => ['type' => 'noEnd', 'startDate' => '2026-08-03'],
        ]);

        self::assertSame('weekly', $rule['frequency'] ?? null);
        self::assertSame(2, $rule['interval'] ?? null);
        self::assertSame('su', $rule['firstDayOfWeek'] ?? null);
        self::assertSame(
            [['@type' => 'NDay', 'day' => 'mo'], ['@type' => 'NDay', 'day' => 'th']],
            $rule['byDay'] ?? null,
        );
    }

    public function testTheLastFridayOfTheMonthIsMinusOneAndNotTheFifthWeek(): void
    {
        // A month with four Fridays has no fifth one. Mapping `last` to 5 skips
        // every such month silently, which is a meeting that simply does not
        // happen eight times a year.
        $rule = $this->mapper()->toJsCalendar([
            'pattern' => [
                'type'       => 'relativeMonthly',
                'interval'   => 1,
                'daysOfWeek' => ['friday'],
                'index'      => 'last',
            ],
            'range' => ['type' => 'noEnd'],
        ]);

        self::assertSame('monthly', $rule['frequency'] ?? null);
        self::assertSame([['@type' => 'NDay', 'day' => 'fr', 'nthOfPeriod' => -1]], $rule['byDay'] ?? null);
    }

    public function testAnEndDateIncludesTheOccurrenceOnThatDay(): void
    {
        // Graph's endDate is the last day an occurrence may start on;
        // JSCalendar's until is an instant. Midnight would drop the final
        // occurrence of every series that does not start at 00:00 — all of them.
        $rule = $this->mapper()->toJsCalendar([
            'pattern' => ['type' => 'daily', 'interval' => 1],
            'range'   => ['type' => 'endDate', 'startDate' => '2026-08-03', 'endDate' => '2026-12-31'],
        ]);

        self::assertSame('2026-12-31T23:59:59', $rule['until'] ?? null);
    }

    public function testANumberedRangeBecomesACountAndNotAnUntil(): void
    {
        $rule = $this->mapper()->toJsCalendar([
            'pattern' => ['type' => 'daily', 'interval' => 1],
            'range'   => ['type' => 'numbered', 'numberOfOccurrences' => 10],
        ]);

        self::assertSame(10, $rule['count'] ?? null);
        self::assertArrayNotHasKey('until', $rule);
    }

    public function testAYearlyPatternKeepsBothTheMonthAndTheDay(): void
    {
        $rule = $this->mapper()->toJsCalendar([
            'pattern' => ['type' => 'absoluteYearly', 'interval' => 1, 'month' => 3, 'dayOfMonth' => 14],
            'range'   => ['type' => 'noEnd'],
        ]);

        self::assertSame('yearly', $rule['frequency'] ?? null);
        // JSCalendar byMonth is strings, because "5L" is how a leap month is
        // said. An int here is not merely a type slip: RecurrenceRuleConverter
        // filters byMonth through a string match and would drop it.
        self::assertSame(['3'], $rule['byMonth'] ?? null);
        self::assertSame([14], $rule['byMonthDay'] ?? null);
    }

    public function testAPatternGraphInventsLaterIsDroppedRatherThanGuessedAt(): void
    {
        self::assertNull($this->mapper()->toJsCalendar(['pattern' => ['type' => 'fortnightlyish']]));
        self::assertNull($this->mapper()->toJsCalendar([]));
    }

    // ── Writing Graph ────────────────────────────────────────────────────────

    public function testAMonthlyRuleWithAnOrdinalGoesOutAsARelativePattern(): void
    {
        $recurrence = $this->mapper()->toGraph(
            [
                '@type'     => 'RecurrenceRule',
                'frequency' => 'monthly',
                'byDay'     => [['@type' => 'NDay', 'day' => 'fr', 'nthOfPeriod' => -1]],
            ],
            new DateTimeImmutable('2026-08-28 09:00:00'),
        );

        self::assertSame('relativeMonthly', $recurrence['pattern']['type'] ?? null);
        self::assertSame(['friday'], $recurrence['pattern']['daysOfWeek'] ?? null);
        self::assertSame('last', $recurrence['pattern']['index'] ?? null);
    }

    public function testAMonthlyRuleWithNoWeekdayGoesOutOnTheDayTheEventStarts(): void
    {
        // Graph's absoluteMonthly requires a dayOfMonth and refuses the request
        // without one. RFC 5545 says a rule with no BYMONTHDAY recurs on the
        // start date's day, so that is what is sent rather than a 400.
        $recurrence = $this->mapper()->toGraph(
            ['@type' => 'RecurrenceRule', 'frequency' => 'monthly'],
            new DateTimeImmutable('2026-08-17 09:00:00'),
        );

        self::assertSame('absoluteMonthly', $recurrence['pattern']['type'] ?? null);
        self::assertSame(17, $recurrence['pattern']['dayOfMonth'] ?? null);
    }

    public function testAWeeklyRuleWithNoDaysStillNamesADayGraphWillAccept(): void
    {
        // An empty daysOfWeek is a 400, and a 400 on a push is written off as
        // permanent — the event would never sync at all.
        $recurrence = $this->mapper()->toGraph(
            ['@type' => 'RecurrenceRule', 'frequency' => 'weekly'],
            new DateTimeImmutable('2026-08-04 09:00:00'),
        );

        self::assertSame(['tuesday'], $recurrence['pattern']['daysOfWeek'] ?? null);
    }

    public function testACountBecomesANumberedRangeAndAnUntilBecomesAnEndDate(): void
    {
        $numbered = $this->mapper()->toGraph(
            ['frequency' => 'daily', 'count' => 5],
            new DateTimeImmutable('2026-08-04 09:00:00'),
        );

        self::assertSame('numbered', $numbered['range']['type'] ?? null);
        self::assertSame(5, $numbered['range']['numberOfOccurrences'] ?? null);
        self::assertSame('2026-08-04', $numbered['range']['startDate'] ?? null);

        $dated = $this->mapper()->toGraph(
            ['frequency' => 'daily', 'until' => '2026-12-31T23:59:59'],
            new DateTimeImmutable('2026-08-04 09:00:00'),
        );

        self::assertSame('endDate', $dated['range']['type'] ?? null);
        self::assertSame('2026-12-31', $dated['range']['endDate'] ?? null);
    }

    public function testARuleWithNoEndConditionSaysSoRatherThanOmittingTheRange(): void
    {
        // Graph rejects a patternedRecurrence with no range at all.
        $recurrence = $this->mapper()->toGraph(
            ['frequency' => 'daily'],
            new DateTimeImmutable('2026-08-04 09:00:00'),
        );

        self::assertSame('noEnd', $recurrence['range']['type'] ?? null);
    }

    public function testAFrequencyGraphCannotExpressIsDroppedRatherThanApproximated(): void
    {
        // Graph has no hourly pattern. Rounding it to daily would put a series
        // in colleagues' calendars on a cadence nobody chose, where dropping it
        // leaves one visibly non-repeating event.
        self::assertNull($this->mapper()->toGraph(
            ['frequency' => 'hourly'],
            new DateTimeImmutable('2026-08-04 09:00:00'),
        ));
    }

    private function mapper(): GraphRecurrenceMapper
    {
        return new GraphRecurrenceMapper();
    }
}
