<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\Graph;

use DateTimeImmutable;

/**
 * Graph's patternedRecurrence and JSCalendar's RecurrenceRule, in both
 * directions.
 *
 * Its own class because the two models disagree about *shape*, not just about
 * spelling. JSCalendar is RFC 5545 restated as JSON — one frequency plus BY*
 * lists, so "the last Friday of every second month" is
 * `{frequency: monthly, interval: 2, byDay: [{day: fr, nthOfPeriod: -1}]}`.
 * Graph instead enumerates six *named patterns* (daily, weekly,
 * absoluteMonthly, relativeMonthly, absoluteYearly, relativeYearly) and puts
 * the end condition in a separate `range` object. Translating between an open
 * grammar and a closed enumeration is a table with real judgement in it, and it
 * belongs somewhere a test can address it directly rather than through an HTTP
 * fixture.
 *
 * **Anything that does not fit is dropped, never approximated.** A rule Graph
 * has no pattern for — JSCalendar's hourly, a monthly rule with several BYDAY
 * entries, a leap month — comes back null and the event is written as a
 * one-off. That is visibly wrong. Half-translating it produces a series on the
 * wrong cadence at the remote, in other people's calendars, which is invisibly
 * wrong; RecurrenceRuleConverter made the same call for the same reason.
 *
 * Only the first rule is considered in either direction. JSCalendar allows a
 * list of recurrenceRules and Graph allows exactly one pattern, so the choice
 * is between the first and nothing.
 */
final readonly class GraphRecurrenceMapper
{
    /** JSCalendar's two-letter weekday to Graph's spelled-out one. */
    private const array WEEKDAYS = [
        'mo' => 'monday',
        'tu' => 'tuesday',
        'we' => 'wednesday',
        'th' => 'thursday',
        'fr' => 'friday',
        'sa' => 'saturday',
        'su' => 'sunday',
    ];

    /**
     * Graph's `index` as the ordinal JSCalendar puts on an NDay.
     *
     * "last" is -1 rather than 5, and that is the whole reason this is a named
     * table: a month with four Fridays has no fifth one, so mapping last to 5
     * silently skips every such month.
     */
    private const array ORDINALS = [
        'first'  => 1,
        'second' => 2,
        'third'  => 3,
        'fourth' => 4,
        'last'   => -1,
    ];

    /**
     * @param array<string,mixed> $recurrence Graph's patternedRecurrence
     *
     * @return array<string,mixed>|null a JSCalendar RecurrenceRule
     */
    public function toJsCalendar(array $recurrence): ?array
    {
        $pattern = $recurrence['pattern'] ?? null;

        if (false === is_array($pattern)) {
            return null;
        }

        $type = is_string($pattern['type'] ?? null) ? strtolower($pattern['type']) : '';

        $rule = match ($type) {
            'daily'           => ['frequency' => 'daily'],
            'weekly'          => ['frequency' => 'weekly'] + $this->weekdaysIn($pattern),
            'absolutemonthly' => ['frequency' => 'monthly'] + $this->monthDayIn($pattern),
            'relativemonthly' => ['frequency' => 'monthly'] + $this->nthWeekdayIn($pattern),
            'absoluteyearly'  => ['frequency' => 'yearly'] + $this->monthIn($pattern) + $this->monthDayIn($pattern),
            'relativeyearly'  => ['frequency' => 'yearly'] + $this->monthIn($pattern) + $this->nthWeekdayIn($pattern),
            default           => null,
        };

        if (null === $rule) {
            return null;
        }

        $interval = $pattern['interval'] ?? 1;

        if (true === is_int($interval) && 1 < $interval) {
            $rule['interval'] = $interval;
        }

        $rule = ['@type' => 'RecurrenceRule'] + $rule + $this->rangeIn($recurrence['range'] ?? null);

        // Written last so the key order reads frequency-first, which is how
        // every example in RFC 8984 is written and how a stored object is read.
        return $rule;
    }

    /**
     * @param array<string,mixed> $rule       one JSCalendar RecurrenceRule
     * @param DateTimeImmutable   $localStart the event's first occurrence in its
     *                                        own zone — Graph requires
     *                                        range.startDate, and the day and
     *                                        month it names are also what an
     *                                        absolute pattern falls back to when
     *                                        the rule does not say
     *
     * @return array<string,mixed>|null Graph's patternedRecurrence
     */
    public function toGraph(array $rule, DateTimeImmutable $localStart): ?array
    {
        $frequency = is_string($rule['frequency'] ?? null) ? strtolower($rule['frequency']) : '';
        $weekday   = $this->firstWeekday($rule);
        $ordinal   = $this->firstOrdinal($rule);

        $pattern = match ($frequency) {
            'daily'  => ['type' => 'daily'],
            'weekly' => [
                'type'       => 'weekly',
                'daysOfWeek' => $this->weekdaysOut($rule, $localStart),
            ],
            'monthly' => null === $weekday
                ? ['type' => 'absoluteMonthly', 'dayOfMonth' => $this->monthDayOut($rule, $localStart)]
                : ['type' => 'relativeMonthly', 'daysOfWeek' => [$weekday], 'index' => $ordinal],
            'yearly' => null === $weekday
                ? [
                    'type'       => 'absoluteYearly',
                    'month'      => $this->monthOut($rule, $localStart),
                    'dayOfMonth' => $this->monthDayOut($rule, $localStart),
                ]
                : [
                    'type'       => 'relativeYearly',
                    'month'      => $this->monthOut($rule, $localStart),
                    'daysOfWeek' => [$weekday],
                    'index'      => $ordinal,
                ],
            // hourly, and every frequency a future JSCalendar writer might use.
            default => null,
        };

        if (null === $pattern) {
            return null;
        }

        $interval = $rule['interval'] ?? 1;

        $pattern['interval'] = true === is_int($interval) && 0 < $interval ? $interval : 1;

        $firstDay = is_string($rule['firstDayOfWeek'] ?? null) ? strtolower($rule['firstDayOfWeek']) : '';

        if (true === array_key_exists($firstDay, self::WEEKDAYS)) {
            $pattern['firstDayOfWeek'] = self::WEEKDAYS[$firstDay];
        }

        return [
            'pattern' => $pattern,
            'range'   => $this->rangeOut($rule, $localStart),
        ];
    }

    // ── Reading Graph ────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $pattern
     *
     * @return array<string,mixed>
     */
    private function weekdaysIn(array $pattern): array
    {
        $days = [];

        foreach ($this->daysOfWeek($pattern) as $day) {
            $days[] = ['@type' => 'NDay', 'day' => $day];
        }

        if ([] === $days) {
            return [];
        }

        $rule = ['byDay' => $days];

        $firstDay = is_string($pattern['firstDayOfWeek'] ?? null) ? strtolower($pattern['firstDayOfWeek']) : '';
        $short    = array_search($firstDay, self::WEEKDAYS, true);

        if (false !== $short) {
            $rule['firstDayOfWeek'] = $short;
        }

        return $rule;
    }

    /**
     * @param array<string,mixed> $pattern
     *
     * @return array<string,mixed>
     */
    private function nthWeekdayIn(array $pattern): array
    {
        $days  = $this->daysOfWeek($pattern);
        $index = is_string($pattern['index'] ?? null) ? strtolower($pattern['index']) : 'first';

        if ([] === $days) {
            return [];
        }

        // Graph allows several days under one index ("the first weekday of the
        // month" is Monday-to-Friday with index first), and JSCalendar spells
        // that as several NDays carrying the same ordinal.
        $ordinal = self::ORDINALS[$index] ?? 1;
        $byDay   = [];

        foreach ($days as $day) {
            $byDay[] = ['@type' => 'NDay', 'day' => $day, 'nthOfPeriod' => $ordinal];
        }

        return ['byDay' => $byDay];
    }

    /**
     * @param array<string,mixed> $pattern
     *
     * @return array<string,mixed>
     */
    private function monthDayIn(array $pattern): array
    {
        $day = $pattern['dayOfMonth'] ?? 0;

        return true === is_int($day) && 0 < $day ? ['byMonthDay' => [$day]] : [];
    }

    /**
     * JSCalendar byMonth is a list of *strings*, because "5L" is how a leap
     * month is said (RFC 8984 §4.3.3). Graph only ever means a Gregorian month.
     *
     * @param array<string,mixed> $pattern
     *
     * @return array<string,mixed>
     */
    private function monthIn(array $pattern): array
    {
        $month = $pattern['month'] ?? 0;

        return true === is_int($month) && 0 < $month ? ['byMonth' => [(string) $month]] : [];
    }

    /**
     * @return list<string> JSCalendar two-letter weekdays
     */
    private function daysOfWeek(mixed $pattern): array
    {
        $days = is_array($pattern) ? ($pattern['daysOfWeek'] ?? null) : null;

        if (false === is_array($days)) {
            return [];
        }

        $mapped = [];

        foreach ($days as $day) {
            if (false === is_string($day)) {
                continue;
            }

            $short = array_search(strtolower($day), self::WEEKDAYS, true);

            if (false !== $short) {
                $mapped[] = $short;
            }
        }

        return $mapped;
    }

    /**
     * Graph's range as JSCalendar's end condition.
     *
     * endDate becomes an UNTIL at the *end* of that day rather than at its
     * midnight. Graph's endDate is a date and means "the last day an occurrence
     * may start on"; JSCalendar's until is a LocalDateTime and means "no
     * occurrence after this instant". A naive midnight therefore drops the final
     * occurrence of every series that does not start at 00:00, which is all of
     * them.
     *
     * range.recurrenceTimeZone is deliberately not carried across. A JSCalendar
     * rule has no zone of its own — it is expanded in the event's timeZone (RFC
     * 8984 §4.3.3) — and RecurrenceMaterialiser does exactly that. Storing a
     * second zone here would create a rule nothing reads and a disagreement
     * nothing resolves.
     *
     * @return array<string,mixed>
     */
    private function rangeIn(mixed $range): array
    {
        if (false === is_array($range)) {
            return [];
        }

        $type = is_string($range['type'] ?? null) ? strtolower($range['type']) : '';

        if ('numbered' === $type) {
            $count = $range['numberOfOccurrences'] ?? 0;

            return true === is_int($count) && 0 < $count ? ['count' => $count] : [];
        }

        if ('enddate' !== $type) {
            return [];
        }

        $endDate = $range['endDate'] ?? null;

        if (false === is_string($endDate) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            return [];
        }

        return ['until' => $endDate . 'T23:59:59'];
    }

    // ── Writing Graph ────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $rule
     *
     * @return array<string,mixed>
     */
    private function rangeOut(array $rule, DateTimeImmutable $localStart): array
    {
        $range = ['type' => 'noEnd', 'startDate' => $localStart->format('Y-m-d')];

        $count = $rule['count'] ?? null;

        if (true === is_int($count) && 0 < $count) {
            $range['type']                = 'numbered';
            $range['numberOfOccurrences'] = $count;

            return $range;
        }

        $until = $rule['until'] ?? null;

        if (true === is_string($until) && 1 === preg_match('/^(\d{4}-\d{2}-\d{2})/', $until, $matches)) {
            $range['type']    = 'endDate';
            $range['endDate'] = $matches[1];
        }

        return $range;
    }

    /**
     * @param array<string,mixed> $rule
     *
     * @return list<string>
     */
    private function weekdaysOut(array $rule, DateTimeImmutable $localStart): array
    {
        $days = [];

        foreach ($this->byDay($rule) as $entry) {
            $day = is_string($entry['day'] ?? null) ? strtolower($entry['day']) : '';

            if (true === array_key_exists($day, self::WEEKDAYS)) {
                $days[] = self::WEEKDAYS[$day];
            }
        }

        // Graph rejects a weekly pattern with an empty daysOfWeek. A JSCalendar
        // weekly rule without byDay means "the day the event starts on", which
        // is what RFC 5545 says an RRULE with no BYDAY means, so that is what is
        // sent rather than letting the whole push fail on a 400.
        return [] === $days ? [strtolower($localStart->format('l'))] : array_values(array_unique($days));
    }

    /**
     * @param array<string,mixed> $rule
     */
    private function monthDayOut(array $rule, DateTimeImmutable $localStart): int
    {
        $days = $rule['byMonthDay'] ?? null;

        if (true === is_array($days) && true === is_int($days[0] ?? null) && 0 < $days[0]) {
            return $days[0];
        }

        return (int) $localStart->format('j');
    }

    /**
     * @param array<string,mixed> $rule
     */
    private function monthOut(array $rule, DateTimeImmutable $localStart): int
    {
        $months = $rule['byMonth'] ?? null;
        $month  = is_array($months) ? ($months[0] ?? null) : null;

        if (true === is_int($month) && 0 < $month && 13 > $month) {
            return $month;
        }

        // "5L" and friends fall through to the event's own month: a leap month
        // cannot be expressed at Graph at all, and refusing the whole event over
        // it would lose the series as well as the leap.
        if (true === is_string($month) && 1 === preg_match('/^([1-9]|1[0-2])$/', $month)) {
            return (int) $month;
        }

        return (int) $localStart->format('n');
    }

    /**
     * Graph's weekday name for the first BYDAY entry, or null when there is
     * none — which is also how monthly and yearly choose between their
     * absolute and relative pattern.
     *
     * @param array<string,mixed> $rule
     */
    private function firstWeekday(array $rule): ?string
    {
        foreach ($this->byDay($rule) as $entry) {
            $day = is_string($entry['day'] ?? null) ? strtolower($entry['day']) : '';

            if (true === array_key_exists($day, self::WEEKDAYS)) {
                return self::WEEKDAYS[$day];
            }
        }

        return null;
    }

    /**
     * Graph's `index` for the first BYDAY entry's ordinal; "first" when it has
     * none.
     *
     * @param array<string,mixed> $rule
     */
    private function firstOrdinal(array $rule): string
    {
        foreach ($this->byDay($rule) as $entry) {
            $nth = $entry['nthOfPeriod'] ?? null;

            if (false === is_int($nth)) {
                continue;
            }

            $index = array_search($nth, self::ORDINALS, true);

            if (false !== $index) {
                return $index;
            }
        }

        return 'first';
    }

    /**
     * @param array<string,mixed> $rule
     *
     * @return list<array<string,mixed>>
     */
    private function byDay(array $rule): array
    {
        $byDay = $rule['byDay'] ?? null;

        if (false === is_array($byDay)) {
            return [];
        }

        return array_values(array_filter($byDay, 'is_array'));
    }
}
