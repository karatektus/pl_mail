<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Service\Calendar\RecurrenceRuleConverter;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one conversion between an RRULE and a JSCalendar rule, in both
 * directions.
 *
 * Two claims, and the second is the reason this file grew.
 *
 * **The editor's repeat dropdown writes something the expander understands.**
 * The dropdown writes a JSCalendar rule and sabre reads an RRULE, and nothing
 * else checks that the first is a thing the second understands — a frequency
 * spelled in a way toRrule() drops produces an event that silently does not
 * recur, which looks exactly like an event that was never meant to.
 *
 * **A rule arriving as an RRULE is converted or refused, never half-converted.**
 * That direction was missing until three callers needed it, and while it was
 * missing a recurring event from a CalDAV server or an emailed invitation was
 * stored verbatim and expanded to one occurrence: a weekly meeting appeared
 * once. Writing it back is only half the job, because the failure mode of a
 * partial conversion is worse than the one it replaces — FREQ=MONTHLY;BYDAY=2FR
 * with an unreadable BYDAY becomes "monthly on the day it started", a meeting
 * somebody misses rather than a meeting visibly missing. So every refusal below
 * is as much the subject as every conversion.
 *
 * Pure, with no collaborators and no container: this is a grammar, and a table
 * is how a grammar is checked.
 */
final class RecurrenceRuleConverterTest extends TestCase
{
    private RecurrenceRuleConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new RecurrenceRuleConverter();
    }

    // ── The editor's dropdown ─────────────────────────────────────────────

    /**
     * @return list<array{string,string}>
     */
    public static function repeatChoices(): array
    {
        return [
            ['daily', 'FREQ=DAILY'],
            ['weekly', 'FREQ=WEEKLY'],
            ['monthly', 'FREQ=MONTHLY'],
            ['yearly', 'FREQ=YEARLY'],
        ];
    }

    #[DataProvider('repeatChoices')]
    public function testEveryDropdownChoiceSurvivesTheTripToAnRrule(string $choice, string $expected): void
    {
        $rule = $this->converter->fromRepeatChoice($choice);

        self::assertIsArray($rule);
        self::assertSame($expected, $this->converter->toRrule($rule));
    }

    public function testTheRuleIsTaggedSoItRoundTripsAsJscalendar(): void
    {
        self::assertSame(
            ['@type' => 'RecurrenceRule', 'frequency' => 'weekly'],
            $this->converter->fromRepeatChoice('weekly'),
        );
    }

    /**
     * "Does not repeat" is the empty choice, and anything unrecognised means
     * the same thing. Guessing at a half-understood value would produce an
     * event on the wrong days, which is worse than one that does not recur.
     */
    public function testAnythingElseMeansItDoesNotRepeat(): void
    {
        self::assertNull($this->converter->fromRepeatChoice(''));
        self::assertNull($this->converter->fromRepeatChoice('never'));
        self::assertNull($this->converter->fromRepeatChoice('hourly'));
    }

    // ── RRULE in ──────────────────────────────────────────────────────────

    /**
     * Every part of RFC 5545's grammar the three sources actually emit.
     *
     * @return array<string,array{string,array<string,mixed>}>
     */
    public static function convertibleRules(): array
    {
        return [
            'a plain frequency' => [
                'FREQ=DAILY',
                ['@type' => 'RecurrenceRule', 'frequency' => 'daily'],
            ],
            'a frequency spelled in lower case' => [
                'freq=weekly',
                ['@type' => 'RecurrenceRule', 'frequency' => 'weekly'],
            ],
            'an interval' => [
                'FREQ=WEEKLY;INTERVAL=3',
                ['@type' => 'RecurrenceRule', 'frequency' => 'weekly', 'interval' => 3],
            ],
            'an interval of one, which is the default and not worth storing' => [
                'FREQ=WEEKLY;INTERVAL=1',
                ['@type' => 'RecurrenceRule', 'frequency' => 'weekly'],
            ],
            'a count' => [
                'FREQ=DAILY;COUNT=10',
                ['@type' => 'RecurrenceRule', 'frequency' => 'daily', 'count' => 10],
            ],
            'a bare-date until, which has no zone to be read in' => [
                'FREQ=DAILY;UNTIL=20261231',
                ['@type' => 'RecurrenceRule', 'frequency' => 'daily', 'until' => '2026-12-31T00:00:00'],
            ],
            'weekdays' => [
                'FREQ=WEEKLY;BYDAY=MO,WE,FR',
                [
                    '@type'     => 'RecurrenceRule',
                    'frequency' => 'weekly',
                    'byDay'     => [
                        ['@type' => 'NDay', 'day' => 'mo'],
                        ['@type' => 'NDay', 'day' => 'we'],
                        ['@type' => 'NDay', 'day' => 'fr'],
                    ],
                ],
            ],
            'an ordinal weekday' => [
                'FREQ=MONTHLY;BYDAY=2FR',
                [
                    '@type'     => 'RecurrenceRule',
                    'frequency' => 'monthly',
                    'byDay'     => [['@type' => 'NDay', 'day' => 'fr', 'nthOfPeriod' => 2]],
                ],
            ],
            'the last Sunday of the month' => [
                'FREQ=MONTHLY;BYDAY=-1SU',
                [
                    '@type'     => 'RecurrenceRule',
                    'frequency' => 'monthly',
                    'byDay'     => [['@type' => 'NDay', 'day' => 'su', 'nthOfPeriod' => -1]],
                ],
            ],
            'a signed ordinal, which RFC 5545 permits and Exchange writes' => [
                'FREQ=MONTHLY;BYDAY=+2WE',
                [
                    '@type'     => 'RecurrenceRule',
                    'frequency' => 'monthly',
                    'byDay'     => [['@type' => 'NDay', 'day' => 'we', 'nthOfPeriod' => 2]],
                ],
            ],
            'a day of the month' => [
                'FREQ=MONTHLY;BYMONTHDAY=15',
                ['@type' => 'RecurrenceRule', 'frequency' => 'monthly', 'byMonthDay' => [15]],
            ],
            'the last day of the month, which is a negative day' => [
                'FREQ=MONTHLY;BYMONTHDAY=-1',
                ['@type' => 'RecurrenceRule', 'frequency' => 'monthly', 'byMonthDay' => [-1]],
            ],
            'a month, which JSCalendar spells as a string' => [
                'FREQ=YEARLY;BYMONTH=3',
                ['@type' => 'RecurrenceRule', 'frequency' => 'yearly', 'byMonth' => ['3']],
            ],
            'the last weekday of the month, which needs BYSETPOS' => [
                'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1',
                [
                    '@type'     => 'RecurrenceRule',
                    'frequency' => 'monthly',
                    'byDay'     => [
                        ['@type' => 'NDay', 'day' => 'mo'],
                        ['@type' => 'NDay', 'day' => 'tu'],
                        ['@type' => 'NDay', 'day' => 'we'],
                        ['@type' => 'NDay', 'day' => 'th'],
                        ['@type' => 'NDay', 'day' => 'fr'],
                    ],
                    'bySetPosition' => [-1],
                ],
            ],
            'a first day of the week' => [
                'FREQ=WEEKLY;INTERVAL=2;BYDAY=TU;WKST=SU',
                [
                    '@type'          => 'RecurrenceRule',
                    'frequency'      => 'weekly',
                    'interval'       => 2,
                    'byDay'          => [['@type' => 'NDay', 'day' => 'tu']],
                    'firstDayOfWeek' => 'su',
                ],
            ],
            'the hour and minute lists, which an .ics generator emits in full' => [
                'FREQ=DAILY;BYHOUR=9,17;BYMINUTE=0',
                [
                    '@type'     => 'RecurrenceRule',
                    'frequency' => 'daily',
                    'byHour'    => [9, 17],
                    'byMinute'  => [0],
                ],
            ],
            'a part nobody standardised, which is dropped rather than refused' => [
                'FREQ=WEEKLY;X-EVOLUTION-ENDDATE=20261231',
                ['@type' => 'RecurrenceRule', 'frequency' => 'weekly'],
            ],
            'a trailing semicolon, which several exporters leave behind' => [
                'FREQ=WEEKLY;',
                ['@type' => 'RecurrenceRule', 'frequency' => 'weekly'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $expected
     */
    #[DataProvider('convertibleRules')]
    public function testAnRruleBecomesTheJscalendarRuleItMeans(string $rrule, array $expected): void
    {
        self::assertSame($expected, $this->converter->fromRrule($rrule));
    }

    /**
     * The rules that must come back null.
     *
     * Every one of them would otherwise convert to something *nearly* right,
     * which is the failure this class exists to refuse: an event on the wrong
     * days is worse than an event that does not recur, because nobody looks at
     * it twice.
     *
     * @return array<string,array{string}>
     */
    public static function refusedRules(): array
    {
        return [
            'no frequency at all, which RFC 5545 forbids and exporters emit' => ['COUNT=5;BYDAY=MO'],
            'a frequency that is not one'                                    => ['FREQ=FORTNIGHTLY'],
            // sabre accepts both at validation and then never advances, so a
            // converted one is a thousand identical rows rather than a hang.
            'every second'                                                   => ['FREQ=SECONDLY;COUNT=5'],
            'every minute'                                                   => ['FREQ=MINUTELY'],
            'a whole line rather than the value'                             => ['RRULE:FREQ=DAILY'],
            'a weekday that is not one'                                      => ['FREQ=WEEKLY;BYDAY=XX'],
            'one good weekday and one that is not'                           => ['FREQ=WEEKLY;BYDAY=MO,XX'],
            'an ordinal on a weekday that is not one'                        => ['FREQ=MONTHLY;BYDAY=2FOO'],
            'an empty BYDAY'                                                 => ['FREQ=WEEKLY;BYDAY='],
            'a day of the month spelled in words'                            => ['FREQ=MONTHLY;BYMONTHDAY=first'],
            'a month that is not one'                                        => ['FREQ=YEARLY;BYMONTH=13'],
            'a leap month, which RRULE cannot express at all'                => ['FREQ=YEARLY;BYMONTH=5L'],
            'an interval of nothing'                                         => ['FREQ=WEEKLY;INTERVAL=0'],
            'an interval that is not a number'                               => ['FREQ=WEEKLY;INTERVAL=two'],
            'a count of nothing'                                             => ['FREQ=DAILY;COUNT=0'],
            'a count that is not a number'                                   => ['FREQ=DAILY;COUNT=many'],
            'an until that is not a date'                                    => ['FREQ=DAILY;UNTIL=whenever'],
            'a first day of the week that is not a day'                      => ['FREQ=WEEKLY;WKST=XX'],
        ];
    }

    #[DataProvider('refusedRules')]
    public function testARuleThatCannotBeCarriedAcrossIsRefusedWholeRatherThanNarrowed(string $rrule): void
    {
        self::assertNull($this->converter->fromRrule($rrule));
    }

    /**
     * Every canonical form comes back as the bytes it went in as.
     *
     * The two directions are used on opposite ends of one round trip — a rule
     * pulled off a server is pushed back to it after an edit — so a conversion
     * that loses a part quietly changes somebody else's calendar.
     *
     * @return list<array{string}>
     */
    public static function roundTrippableRules(): array
    {
        return [
            ['FREQ=DAILY'],
            ['FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE'],
            ['FREQ=MONTHLY;COUNT=6;BYDAY=-1FR'],
            ['FREQ=MONTHLY;BYMONTHDAY=15'],
            ['FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1'],
            ['FREQ=YEARLY;BYMONTH=3'],
            ['FREQ=WEEKLY;BYDAY=TU;WKST=SU'],
        ];
    }

    #[DataProvider('roundTrippableRules')]
    public function testARuleSurvivesTheTripThereAndBack(string $rrule): void
    {
        $rule = $this->converter->fromRrule($rrule);

        self::assertIsArray($rule);
        self::assertSame($rrule, $this->converter->toRrule($rule));
    }

    /**
     * UNTIL is a UTC instant in iCalendar and a LocalDateTime in JSCalendar,
     * read in the event's own zone.
     *
     * Carried across unconverted it ends a Berlin series two hours early —
     * visible only as a missing last occurrence, which is the kind of bug
     * nobody reports.
     */
    public function testUntilIsReadIntoTheEventsOwnZone(): void
    {
        self::assertSame(
            '2026-12-31T22:59:59',
            $this->converter->fromRrule('FREQ=WEEKLY;UNTIL=20261231T215959Z', 'Europe/Berlin')['until'] ?? null,
        );

        self::assertSame(
            '2026-12-31T21:59:59',
            $this->converter->fromRrule('FREQ=WEEKLY;UNTIL=20261231T215959Z')['until'] ?? null,
            'with no zone to read it in, the instant stands as it was written',
        );
    }

    /**
     * COUNT and UNTIL are mutually exclusive in RFC 5545 and a hand-edited rule
     * carries both anyway. COUNT wins in both directions, so a round trip does
     * not depend on which way it went first.
     */
    public function testCountBeatsUntilTheSameWayInBothDirections(): void
    {
        $rule = $this->converter->fromRrule('FREQ=DAILY;COUNT=3;UNTIL=20261231T000000Z');

        self::assertSame(3, $rule['count'] ?? null);
        self::assertArrayNotHasKey('until', (array) $rule);
        self::assertSame('FREQ=DAILY;COUNT=3', $this->converter->toRrule((array) $rule));
    }

    // ── recurrenceOverrides ───────────────────────────────────────────────

    /**
     * The key is the instance's ORIGINAL local start, which is what
     * RecurrenceMaterialiser looks a patch up by.
     *
     * In the event's own zone, not UTC: a Berlin series expands to 09:00 Berlin,
     * so a key written as the 08:00 UTC behind it matches nothing at all and the
     * override does nothing — silently, which is the whole problem with it.
     */
    public function testAnOverrideKeyIsTheOriginalStartInTheEventsOwnZone(): void
    {
        $instant = new DateTimeImmutable('2026-08-11 08:00:00', new DateTimeZone('UTC'));

        self::assertSame(
            '2026-08-11T10:00:00',
            $this->converter->overrideKey($instant, new DateTimeZone('Europe/Berlin')),
        );

        self::assertSame(
            '2026-08-11T08:00:00',
            $this->converter->overrideKey($instant, new DateTimeZone('UTC')),
        );
    }

    /** EXDATE, said the way RFC 8984 says it. */
    public function testAnExcludedInstanceIsSaidWithTheOneValueTheExpanderSkipsOn(): void
    {
        $overrides = $this->converter->exclusionOverrides(
            [
                new DateTimeImmutable('2026-08-11 08:00:00', new DateTimeZone('UTC')),
                new DateTimeImmutable('2026-08-18 08:00:00', new DateTimeZone('UTC')),
            ],
            new DateTimeZone('Europe/Berlin'),
        );

        self::assertSame([
            '2026-08-11T10:00:00' => ['excluded' => true],
            '2026-08-18T10:00:00' => ['excluded' => true],
        ], $overrides);

        self::assertSame([], $this->converter->exclusionOverrides([], new DateTimeZone('UTC')));
    }
}
