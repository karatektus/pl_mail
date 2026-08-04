<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * iCalendar recurrence and JSCalendar recurrence (RFC 8984 §4.3.3), in both
 * directions.
 *
 * Needed because the two standards disagree about exactly one thing, and it is
 * this one. JSCalendar is the better model to store — named fields, JSON, no
 * parsing — but every library that can actually expand a recurrence, sabre
 * included, speaks RRULE. So the rule is stored as JSCalendar and converted
 * here on the way into the expander.
 *
 * **Both directions live here, and that is the point.** The reverse used to be
 * missing, so two of the three ways a rule reaches plMail — an emailed invite
 * and a CalDAV resource — kept it verbatim under `plmail:rrule` and expanded to
 * a single occurrence: a weekly meeting from a calendar server showed up once.
 * The third, Google, wrote its own copy because there was nothing to call. One
 * grammar with one set of corners is worth more than three implementations that
 * agree until the day one of them is fixed.
 *
 * **Anything that cannot be converted faithfully refuses the whole rule.** Not
 * "drops the part it did not understand": a rule that half-converts produces an
 * event on the wrong days, which is worse than an event that does not recur —
 * FREQ=MONTHLY;BYDAY=2FR with an unreadable BYDAY becomes "monthly on the day it
 * started", which is a meeting somebody misses rather than a meeting visibly
 * missing. A refused rule comes back null and the caller keeps the RRULE
 * verbatim, so nothing is lost and a push puts the sender's own rule back.
 *
 * The one thing that is dropped rather than refused is a part name RFC 5545
 * does not define. Its grammar is closed and every part in it is handled below,
 * so an unrecognised name is a vendor extension (`X-…`) and never a narrowing of
 * the rule.
 */
final readonly class RecurrenceRuleConverter
{
    /**
     * JSCalendar's LocalDateTime: no offset, no trailing Z (RFC 8984 §4.1.2).
     *
     * Spelled once because it is also the key format of recurrenceOverrides, and
     * a producer and RecurrenceMaterialiser that formatted that key differently
     * would file every override under a key the expander never looks up — an
     * override that silently does nothing.
     */
    private const string LOCAL_DATE_TIME = 'Y-m-d\TH:i:s';

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
     * The RRULE parts that are a plain list of integers, and the JSCalendar key
     * each becomes.
     *
     * One table read in both directions, so a part that converts one way
     * converts back. BYMONTH is not in it because JSCalendar spells months as
     * strings, and BYDAY is not because it carries an ordinal.
     */
    private const array INT_LISTS = [
        'BYMONTHDAY' => 'byMonthDay',
        'BYYEARDAY'  => 'byYearDay',
        'BYWEEKNO'   => 'byWeekNo',
        'BYHOUR'     => 'byHour',
        'BYMINUTE'   => 'byMinute',
        'BYSECOND'   => 'bySecond',
        'BYSETPOS'   => 'bySetPosition',
    ];

    /**
     * The event editor's repeat dropdown, as a JSCalendar rule.
     *
     * Deliberately not a general recurrence editor — that is a UI of its own,
     * and these four cover what a person types into a calendar by hand.
     * Anything else means "does not repeat", including the empty choice.
     *
     * @return array<string,mixed>|null
     */
    public function fromRepeatChoice(string $repeat): ?array
    {
        return match ($repeat) {
            'daily'   => ['@type' => 'RecurrenceRule', 'frequency' => 'daily'],
            'weekly'  => ['@type' => 'RecurrenceRule', 'frequency' => 'weekly'],
            'monthly' => ['@type' => 'RecurrenceRule', 'frequency' => 'monthly'],
            'yearly'  => ['@type' => 'RecurrenceRule', 'frequency' => 'yearly'],
            default   => null,
        };
    }

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

        foreach (self::INT_LISTS as $icalKey => $jsKey) {
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

        $firstDay = is_string($rule['firstDayOfWeek'] ?? null)
            ? mb_strtolower($rule['firstDayOfWeek'])
            : '';

        if ('' !== $firstDay && true === array_key_exists($firstDay, self::WEEKDAYS)) {
            $parts[] = 'WKST=' . self::WEEKDAYS[$firstDay];
        }

        return implode(';', $parts);
    }

    /**
     * The JSCalendar rule behind one RRULE value, or null when it cannot be
     * carried across without changing which days it means.
     *
     * $rrule is the value alone — "FREQ=WEEKLY;BYDAY=MO" — which is what casting
     * a sabre RRULE property to a string gives and what Google's `recurrence`
     * lines carry after their prefix. A whole line handed in here has no FREQ
     * once it is split, so it refuses rather than converting something else.
     *
     * $timeZone is the event's own zone, and it is needed for exactly one thing:
     * UNTIL arrives as a UTC instant ("20261231T215959Z") and JSCalendar's
     * `until` is a LocalDateTime read in the event's zone (RFC 8984 §4.3.3).
     * Formatting the UTC wall time straight into it would end a Berlin series
     * two hours early in winter — visible only as a missing last occurrence,
     * which is the kind of bug nobody reports.
     *
     * @return array<string,mixed>|null
     */
    public function fromRrule(string $rrule, ?string $timeZone = null): ?array
    {
        $parts = $this->partsOf($rrule);

        // Derived from the forward table rather than written out again, so the
        // two directions cannot disagree about which frequencies exist. The
        // absentees are secondly and minutely — see FREQUENCIES.
        $frequency = array_flip(self::FREQUENCIES)[mb_strtoupper($parts['FREQ'] ?? '')] ?? null;

        if (null === $frequency) {
            // Includes the rule with no FREQ at all, which RFC 5545 forbids and
            // some exporters emit anyway.
            return null;
        }

        $rule = [
            '@type'     => 'RecurrenceRule',
            'frequency' => $frequency,
        ];

        if (true === array_key_exists('INTERVAL', $parts)) {
            $interval = $this->positiveInt($parts['INTERVAL']);

            if (null === $interval) {
                return null;
            }

            // 1 is the default, and writing it would make an object that does
            // not round-trip to the same bytes for no gain.
            if (1 < $interval) {
                $rule['interval'] = $interval;
            }
        }

        // COUNT and UNTIL are mutually exclusive in RFC 5545, and COUNT wins
        // where a hand-edited rule carries both — the same arbitrary-but-
        // consistent choice toRrule() makes in the other direction, so a round
        // trip does not depend on which way it went.
        if (true === array_key_exists('COUNT', $parts)) {
            $count = $this->positiveInt($parts['COUNT']);

            if (null === $count) {
                return null;
            }

            $rule['count'] = $count;
        } elseif (true === array_key_exists('UNTIL', $parts)) {
            $until = $this->localUntil($parts['UNTIL'], $timeZone);

            if (null === $until) {
                return null;
            }

            $rule['until'] = $until;
        }

        if (true === array_key_exists('BYDAY', $parts)) {
            $byDay = $this->nDays($parts['BYDAY']);

            if (null === $byDay) {
                return null;
            }

            $rule['byDay'] = $byDay;
        }

        foreach (self::INT_LISTS as $icalKey => $jsKey) {
            if (false === array_key_exists($icalKey, $parts)) {
                continue;
            }

            $values = $this->signedInts($parts[$icalKey]);

            if (null === $values) {
                return null;
            }

            $rule[$jsKey] = $values;
        }

        // JSCalendar months are strings, not integers: "5L" is a leap month in
        // the Hebrew and Chinese calendars, which RRULE cannot express at all,
        // so nothing coming from here will ever be one.
        if (true === array_key_exists('BYMONTH', $parts)) {
            $months = $this->months($parts['BYMONTH']);

            if (null === $months) {
                return null;
            }

            $rule['byMonth'] = $months;
        }

        if (true === array_key_exists('WKST', $parts)) {
            $firstDay = mb_strtolower($parts['WKST']);

            if (false === array_key_exists($firstDay, self::WEEKDAYS)) {
                return null;
            }

            $rule['firstDayOfWeek'] = $firstDay;
        }

        return $rule;
    }

    /**
     * The key one instance of a series is filed under in recurrenceOverrides.
     *
     * The instance's ORIGINAL start, as a LocalDateTime in the event's own zone
     * — not where it was moved to. That is the only stable name for "the one
     * that was meant to be on the 3rd" once somebody has dragged it to the 5th,
     * and it is what a later update matches on.
     *
     * Every producer of an override and RecurrenceMaterialiser, which reads
     * them, go through here. A key written in one format and looked up in
     * another is an override that silently does nothing.
     */
    public function overrideKey(DateTimeInterface $originalStart, DateTimeZone $zone): string
    {
        return DateTimeImmutable::createFromInterface($originalStart)
            ->setTimezone($zone)
            ->format(self::LOCAL_DATE_TIME);
    }

    /**
     * The instances an EXDATE takes off the series, as JSCalendar overrides.
     *
     * `{"excluded": true}` is RFC 8984's EXDATE and is what
     * RecurrenceMaterialiser skips. Spelled once, here, because it is the one
     * override value that has to be exactly right: anything else in that slot is
     * an instance that keeps being drawn after it was called off.
     *
     * @param list<DateTimeInterface> $originalStarts
     *
     * @return array<string,array<string,mixed>>
     */
    public function exclusionOverrides(array $originalStarts, DateTimeZone $zone): array
    {
        $overrides = [];

        foreach ($originalStarts as $originalStart) {
            $overrides[$this->overrideKey($originalStart, $zone)] = ['excluded' => true];
        }

        return $overrides;
    }

    // ── JSCalendar out ────────────────────────────────────────────────────────

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
        $parsed = DateTimeImmutable::createFromFormat(self::LOCAL_DATE_TIME, $until, new DateTimeZone('UTC'));

        if (false !== $parsed) {
            return $parsed;
        }

        try {
            return new DateTimeImmutable($until, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    // ── iCalendar in ──────────────────────────────────────────────────────────

    /**
     * An RRULE value split into its parts, upper-cased on both sides of the
     * equals sign's left half.
     *
     * A part with no `=` in it is skipped rather than refusing the rule: it is
     * not a part with a value this could misread, and a trailing semicolon —
     * which several exporters emit — is otherwise a rule that does not convert.
     *
     * @return array<string,string>
     */
    private function partsOf(string $rrule): array
    {
        $parts = [];

        foreach (explode(';', $rrule) as $part) {
            $pair = explode('=', $part, 2);

            if (2 === count($pair) && '' !== trim($pair[0])) {
                $parts[mb_strtoupper(trim($pair[0]))] = trim($pair[1]);
            }
        }

        return $parts;
    }

    /**
     * BYDAY as JSCalendar NDays, or null when any entry in it is unreadable.
     *
     * The ordinal is the whole reason this cannot be a plain map: "2FR" is the
     * second Friday of the period and "-1SU" the last Sunday, and an entry whose
     * ordinal was dropped means every Friday instead of one of them.
     *
     * @return list<array<string,mixed>>|null
     */
    private function nDays(string $value): ?array
    {
        $days = [];

        foreach (explode(',', $value) as $entry) {
            if (1 !== preg_match('/^([+-]?\d+)?([A-Z]{2})$/i', trim($entry), $match)) {
                return null;
            }

            $day = mb_strtolower($match[2]);

            if (false === array_key_exists($day, self::WEEKDAYS)) {
                return null;
            }

            $nDay = ['@type' => 'NDay', 'day' => $day];

            if ('' !== $match[1]) {
                $nDay['nthOfPeriod'] = (int) $match[1];
            }

            $days[] = $nDay;
        }

        // No emptiness check: explode() always yields at least one entry, and
        // the entry that is not a weekday has already refused the rule above.
        return $days;
    }

    /**
     * @return list<int>|null
     */
    private function signedInts(string $value): ?array
    {
        $ints = [];

        foreach (explode(',', $value) as $entry) {
            if (1 !== preg_match('/^[+-]?\d+$/', trim($entry))) {
                return null;
            }

            $ints[] = (int) trim($entry);
        }

        return $ints;
    }

    /**
     * BYMONTH as the strings JSCalendar wants, or null when a month is not one.
     *
     * @return list<string>|null
     */
    private function months(string $value): ?array
    {
        $months = [];

        foreach (explode(',', $value) as $entry) {
            if (1 !== preg_match('/^([1-9]|1[0-2])$/', trim($entry))) {
                return null;
            }

            $months[] = trim($entry);
        }

        return $months;
    }

    private function positiveInt(string $value): ?int
    {
        if (1 !== preg_match('/^[1-9]\d*$/', trim($value))) {
            return null;
        }

        return (int) trim($value);
    }

    /**
     * UNTIL as a JSCalendar LocalDateTime, in the event's own zone.
     *
     * Both iCalendar forms are accepted: the UTC instant ("20261231T215959Z")
     * that Google and most servers send, and the bare date ("20261231") that a
     * floating or all-day series carries. A bare date has no zone to be read in,
     * so it is not converted — moving it would end an all-day series a day early
     * for anybody east of UTC.
     */
    private function localUntil(string $until, ?string $timeZone): ?string
    {
        $value = trim($until);

        if ('' === $value) {
            return null;
        }

        try {
            $parsed = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        $zone = $this->zoneOrNull($timeZone);

        if (null !== $zone && true === str_ends_with(mb_strtoupper($value), 'Z')) {
            $parsed = $parsed->setTimezone($zone);
        }

        return $parsed->format(self::LOCAL_DATE_TIME);
    }

    private function zoneOrNull(?string $timeZone): ?DateTimeZone
    {
        if (null === $timeZone || '' === $timeZone) {
            return null;
        }

        try {
            return new DateTimeZone($timeZone);
        } catch (\Exception) {
            return null;
        }
    }
}
