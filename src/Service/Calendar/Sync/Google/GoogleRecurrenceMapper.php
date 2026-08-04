<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\Google;

use App\Service\Calendar\RecurrenceRuleConverter;

/**
 * Google's `recurrence` array and JSCalendar's `recurrenceRules`, in both
 * directions.
 *
 * Google carries recurrence as raw iCalendar lines — `["RRULE:FREQ=WEEKLY;
 * BYDAY=MO", "EXDATE;TZID=Europe/Berlin:20260817T100000"]` — and plMail stores
 * JSCalendar, so something has to translate. Both directions of the *rule*
 * itself are RecurrenceRuleConverter's, and are used here rather than
 * reimplemented. This class is what is left once they are: which of Google's
 * lines is the rule, and what happens to the ones that are not.
 *
 * The pull direction used to be written out again here, because at the time
 * there was nothing to call. Lifting it up was not tidying — the same
 * conversion was missing from the CalDAV driver and from IcsEventExtractor,
 * where a recurring event was kept verbatim and expanded to a single
 * occurrence, so a weekly meeting from a calendar server showed up once.
 *
 * **Only the first RRULE is converted.** RFC 5545 permits several and no
 * calendar anybody actually uses emits more than one; JSCalendar's
 * recurrenceRules is a list, so a second rule could be carried, but the local
 * expander reads recurrenceRules[0] and nothing else. Converting a second rule
 * into a slot nothing reads would claim a fidelity this does not have.
 *
 * **EXDATE and RDATE are preserved verbatim rather than converted**, and that
 * is deliberately not the same answer the iCalendar mappings give. A Google
 * push replaces `recurrence` wholesale, so a line that did not survive the
 * round trip is a line deleted at Google: without this, editing the title of a
 * series here would resurrect every instance the user had cancelled in Google's
 * own interface. The display side does not need them either, because Google
 * reports a cancelled instance as its own resource carrying `recurringEventId`
 * — which GoogleEventMapper turns into a real recurrenceOverride — where an
 * .ics has nothing but the EXDATE. Kept under a namespaced extension key, the
 * same device GraphEventMapper uses for `plmail:graphShowAs`.
 */
final readonly class GoogleRecurrenceMapper
{
    /**
     * Where the iCalendar lines that have no JSCalendar home are kept.
     *
     * Namespaced because RFC 8984 §3.3 reserves unprefixed property names, and
     * an unprefixed key here would collide the day the spec grows one.
     */
    public const string PRESERVED_LINES = 'plmail:googleRecurrence';

    public function __construct(
        private RecurrenceRuleConverter $converter,
    ) {
    }

    /**
     * The JSCalendar rule behind Google's recurrence lines, or null when there
     * is no usable RRULE among them.
     *
     * $timeZone is the event's own zone, and what it is for is on
     * RecurrenceRuleConverter::fromRrule(): UNTIL arrives as a UTC instant and
     * JSCalendar's `until` is a LocalDateTime read in the event's zone.
     *
     * @param list<mixed> $recurrence Google's `recurrence` array, verbatim
     *
     * @return array<string,mixed>|null
     */
    public function toJsCalendarRule(array $recurrence, ?string $timeZone): ?array
    {
        foreach ($recurrence as $line) {
            if (true === is_string($line) && 1 === preg_match('/^RRULE[:;](.*)$/is', trim($line), $match)) {
                return $this->converter->fromRrule($match[1], $timeZone);
            }
        }

        return null;
    }

    /**
     * The iCalendar lines that carry no rule, kept so a push can put them back.
     *
     * @param list<mixed> $recurrence
     *
     * @return list<string>
     */
    public function preservedLines(array $recurrence): array
    {
        $lines = [];

        foreach ($recurrence as $line) {
            if (false === is_string($line) || '' === trim($line)) {
                continue;
            }

            if (1 === preg_match('/^RRULE[:;]/i', trim($line))) {
                continue;
            }

            $lines[] = trim($line);
        }

        return $lines;
    }

    /**
     * Google's `recurrence` for an event about to be written, or null when the
     * event does not repeat.
     *
     * Null rather than an empty array, because the two say different things to
     * a PATCH: an empty array clears the recurrence and turns a series into a
     * single event, which is right when the local rule has genuinely been
     * removed, and null omits the field — see GoogleEventMapper, which decides
     * which of the two the event means.
     *
     * @param array<string,mixed> $jscalendar
     *
     * @return list<string>|null
     */
    public function toGoogleRecurrence(array $jscalendar): ?array
    {
        $rules = $jscalendar['recurrenceRules'] ?? null;
        $first = true === is_array($rules) ? ($rules[0] ?? null) : null;

        if (false === is_array($first)) {
            return null;
        }

        $rrule = $this->converter->toRrule($first);

        if (null === $rrule) {
            return null;
        }

        $lines     = ['RRULE:' . $rrule];
        $preserved = $jscalendar[self::PRESERVED_LINES] ?? null;

        foreach (true === is_array($preserved) ? $preserved : [] as $line) {
            if (true === is_string($line) && '' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
