<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use DateTimeImmutable;

/**
 * JSCalendar recurrence rules (RFC 8984 §4.3.3) to an iCalendar RRULE string.
 *
 * Needed because the two standards disagree about exactly one thing, and it is
 * this one. JSCalendar is the better model to store — named fields, JSON, no
 * parsing — but every library that can actually expand a recurrence, sabre
 * included, speaks RRULE. So the rule is stored as JSCalendar and converted
 * here on the way into the expander.
 *
 * Deliberately one-directional. The reverse (RRULE to JSCalendar) is needed
 * when an .ics is imported, and belongs with that code — writing it now, with
 * no caller and no fixtures, would be guessing at which of RRULE's corners
 * matter.
 *
 * Anything this does not recognise is dropped rather than guessed at. A rule
 * that half-converts produces an event on the wrong days, which is worse than
 * an event that does not recur.
 */
final readonly class RecurrenceRuleConverter
{
    /**
     * JSCalendar frequency values to their RRULE FREQ.
     *
     * secondly and minutely are missing on purpose. Both are legal in RFC 5545
     * and RFC 8984, and sabre's RRuleIterator accepts them at validation — but
     * its advance step has no branch for either, so it yields the same instant
     * forever. Converting them would produce an iterator that never moves,
     * which the occurrence cap turns from a hang into a thousand identical
     * rows: worse, because it looks like it worked.
     *
     * Dropping them here means such a rule degrades to a single occurrence,
     * which is visibly wrong rather than quietly wrong. Nothing legitimate
     * schedules a calendar event every second.
     */
    private const array FREQUENCIES = [
        'yearly'  => 'YEARLY',
        'monthly' => 'MONTHLY',
        'weekly'  => 'WEEKLY',
        'daily'   => 'DAILY',
        'hourly'  => 'HOURLY',
    ];

    private const array WEEKDAYS = [
        'mo' => 'MO', 'tu' => 'TU', 'we' => 'WE', 'th' => 'TH',
        'fr' => 'FR', 'sa' => 'SA', 'su' => 'SU',
    ];

    /**
     * @param array<string,mixed> $rule one JSCalendar RecurrenceRule
     *
     * @return string|null an RRULE value ("FREQ=WEEKLY;BYDAY=MO"), or null if
     *                     the rule has no usable frequency
     */
    public function toRrule(array $rule): ?string
    {
        $frequency = is_string($rule['frequency'] ?? null)
            ? mb_strtolower($rule['frequency'])
            : '';

        if (false === array_key_exists($frequency, self::FREQUENCIES)) {
            return null;
        }

        $parts = ['FREQ=' . self::FREQUENCIES[$frequency]];

        $interval = $rule['interval'] ?? 1;

        if (true === is_int($interval) && $interval > 1) {
            $parts[] = 'INTERVAL=' . $interval;
        }

        // COUNT and UNTIL are mutually exclusive in RFC 5545. JSCalendar says
        // the same, but a hand-edited object can carry both; COUNT wins,
        // arbitrarily but consistently, so the result is at least deterministic.
        if (true === is_int($rule['count'] ?? null)) {
            $parts[] = 'COUNT=' . $rule['count'];
        } elseif (true === is_string($rule['until'] ?? null)) {
            $until = $this->parseUntil($rule['until']);

            if (null !== $until) {
                $parts[] = 'UNTIL=' . $until->format('Ymd\THis');
            }
        }

        if (null !== $byDay = $this->byDay($rule['byDay'] ?? null)) {
            $parts[] = 'BYDAY=' . $byDay;
        }

        foreach ([
            'byMonthDay' => 'BYMONTHDAY',
            'byYearDay'  => 'BYYEARDAY',
            'byWeekNo'   => 'BYWEEKNO',
            'byHour'     => 'BYHOUR',
            'byMinute'   => 'BYMINUTE',
            'bySecond'   => 'BYSECOND',
        ] as $jsKey => $icalKey) {
            if (null !== $values = $this->intList($rule[$jsKey] ?? null)) {
                $parts[] = $icalKey . '=' . $values;
            }
        }

        // byMonth is strings in JSCalendar ("1", and "5L" for a leap month),
        // not ints. Leap months are Hebrew/Chinese calendar territory and RRULE
        // has no way to say it, so those are dropped rather than mangled.
        if (null !== $byMonth = $this->byMonth($rule['byMonth'] ?? null)) {
            $parts[] = 'BYMONTH=' . $byMonth;
        }

        if (null !== $bySetPos = $this->intList($rule['bySetPosition'] ?? null)) {
            $parts[] = 'BYSETPOS=' . $bySetPos;
        }

        $firstDay = is_string($rule['firstDayOfWeek'] ?? null)
            ? mb_strtolower($rule['firstDayOfWeek'])
            : '';

        if ('' !== $firstDay && true === array_key_exists($firstDay, self::WEEKDAYS)) {
            $parts[] = 'WKST=' . self::WEEKDAYS[$firstDay];
        }

        return implode(';', $parts);
    }

    /**
     * BYDAY carries an optional ordinal in JSCalendar's nthOfPeriod, which is
     * how "the last Friday of the month" is said: {"day":"fr","nthOfPeriod":-1}
     * becomes -1FR.
     */
    private function byDay(mixed $byDay): ?string
    {
        if (false === is_array($byDay) || 0 === count($byDay)) {
            return null;
        }

        $days = [];

        foreach ($byDay as $entry) {
            if (false === is_array($entry)) {
                continue;
            }

            $day = is_string($entry['day'] ?? null) ? mb_strtolower($entry['day']) : '';

            if (false === array_key_exists($day, self::WEEKDAYS)) {
                continue;
            }

            $nth = $entry['nthOfPeriod'] ?? null;

            $days[] = (true === is_int($nth) && 0 !== $nth)
                ? $nth . self::WEEKDAYS[$day]
                : self::WEEKDAYS[$day];
        }

        return 0 === count($days) ? null : implode(',', $days);
    }

    private function byMonth(mixed $byMonth): ?string
    {
        if (false === is_array($byMonth) || 0 === count($byMonth)) {
            return null;
        }

        $months = [];

        foreach ($byMonth as $month) {
            if (true === is_int($month)) {
                $months[] = (string) $month;

                continue;
            }

            // "5L" is a leap month; RRULE cannot express it.
            if (true === is_string($month) && 1 === preg_match('/^\d{1,2}$/', $month)) {
                $months[] = $month;
            }
        }

        return 0 === count($months) ? null : implode(',', $months);
    }

    private function intList(mixed $values): ?string
    {
        if (false === is_array($values) || 0 === count($values)) {
            return null;
        }

        $ints = array_values(array_filter($values, 'is_int'));

        return 0 === count($ints) ? null : implode(',', $ints);
    }

    /**
     * JSCalendar UNTIL is a LocalDateTime — no zone, no trailing Z. It is read
     * in the event's own zone by the caller, so this only has to parse it.
     */
    private function parseUntil(string $until): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $until, new \DateTimeZone('UTC'));

        if (false !== $parsed) {
            return $parsed;
        }

        try {
            return new DateTimeImmutable($until, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
