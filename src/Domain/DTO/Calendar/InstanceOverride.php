<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use App\Entity\Calendar\CalendarEvent;
use DateTimeImmutable;
use DateTimeZone;

/**
 * One entry of a series' recurrenceOverrides, resolved into instants.
 *
 * A stored override is a JSCalendar PatchObject filed under a LocalDateTime in
 * the series' own zone, and neither half of that is what a provider's API wants:
 * both Google and Microsoft address an instance by an instant, and both are told
 * where it goes with an instant. Something has to do that conversion, and doing
 * it inside each driver is two implementations of one piece of arithmetic that
 * has a DST boundary in it — a Berlin standup's key reads 09:00 all year and is
 * 08:00Z in July and 09:00Z in January, so a driver that resolved the key in UTC
 * would address the wrong occurrence for half the year and find nothing.
 *
 * So this is the one place a stored override becomes times, and it is a value
 * object rather than a service because it is a pure function of one row: no I/O,
 * no state, and testable by handing it an event.
 *
 * **Everything is filled in, including what the patch does not say.** A patch is
 * a partial — an instance that only changed length carries a `duration` and no
 * `start` — and a caller that had to re-derive the missing halves would be the
 * third place that knows an absent `start` means "where the rule put it" and an
 * absent `duration` means "as long as the series". $startsAt and $endsAt are
 * therefore always the instance's real times, and $title is null only when the
 * instance was never renamed, which is the one case a caller must be able to
 * tell apart: sending the series' title for an instance that does not carry one
 * would state a rename that never happened.
 *
 * $isExcluded is kept beside the times rather than instead of them, so a caller
 * branches on one field. An excluded instance's times are meaningless and are
 * the ones the rule gives, which is the honest answer to "when would it have
 * been?" and never used for anything else.
 *
 * CalDavEventConverter::addOverrides() does the same reading and is deliberately
 * not built on this. It writes the whole resource in one pass, needs the values
 * as sabre properties in the series' own zone rather than as UTC instants, and
 * carries the two shapes iCalendar has (an override VEVENT and an EXDATE on the
 * master) rather than the one shape an HTTP API has. Sharing this there would
 * mean converting to UTC and straight back.
 */
final readonly class InstanceOverride
{
    /**
     * JSCalendar's LocalDateTime, which is the key format of recurrenceOverrides
     * (RFC 8984 §4.1.2). Spelled the same way RecurrenceRuleConverter writes it
     * — a key parsed by a format the producer does not use is an override that
     * silently resolves to nothing.
     */
    private const string LOCAL_DATE_TIME = '!Y-m-d\TH:i:s';

    /**
     * @param string            $key           the override's own key, verbatim,
     *                                         so a caller can match a provider's
     *                                         answer back to the map it came from
     * @param DateTimeImmutable $originalStart UTC. Where the rule put this
     *                                         instance, which is the only name it
     *                                         keeps once it has been dragged, and
     *                                         what both providers address an
     *                                         instance by.
     * @param bool              $isExcluded    the instance is off; the times
     *                                         below are where it would have been
     * @param DateTimeImmutable $startsAt      UTC, where the instance actually is
     * @param DateTimeImmutable $endsAt        UTC, exclusive
     * @param string|null       $title         the name this instance was given,
     *                                         null when it was never renamed
     */
    public function __construct(
        public string            $key,
        public DateTimeImmutable $originalStart,
        public bool              $isExcluded,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public ?string           $title,
    ) {
    }

    /**
     * Every override a series carries, in the order they are stored.
     *
     * An event with no overrides, no times, or a recurrenceOverrides that is not
     * a map answers the empty list: this is read from stored JSON, and the
     * caller is a driver mid-push whose job is not to have an opinion about a
     * hand-edited object.
     *
     * **A series that is no longer one answers the empty list too**, however
     * many overrides it still carries. CalendarEventWriter keeps the map through
     * every edit, so a series demoted to a single event keeps the patches it had
     * — and RecurrenceMaterialiser never reads them again, because it only
     * consults them while expanding a rule. Sending them anyway would ask a
     * provider to change occurrences that exist neither there nor in any local
     * view, once per override, and be refused each time.
     *
     * An entry whose key is not a LocalDateTime is skipped rather than refused.
     * There is no instance it could name, so there is nothing to send and
     * nothing a provider could do with it.
     *
     * @return list<self>
     */
    public static function listOf(CalendarEvent $event): array
    {
        $overrides = $event->jscalendar['recurrenceOverrides'] ?? null;
        $startsAt  = $event->startsAt;
        $endsAt    = $event->endsAt;

        if (false === $event->isRecurring || false === is_array($overrides)) {
            return [];
        }

        if (null === $startsAt || null === $endsAt) {
            return [];
        }

        $zone     = self::zoneOf($event);
        $duration = $endsAt->getTimestamp() - $startsAt->getTimestamp();
        $rest     = [];

        foreach ($overrides as $key => $patch) {
            if (false === is_string($key) || false === is_array($patch)) {
                continue;
            }

            $originalStart = self::localInstant($key, $zone);

            if (null === $originalStart) {
                continue;
            }

            $moved = true === is_string($patch['start'] ?? null)
                ? self::localInstant($patch['start'], $zone)
                : null;

            $instanceStart = $moved ?? $originalStart;
            $title         = $patch['title'] ?? null;

            $rest[] = new self(
                key:           $key,
                originalStart: $originalStart,
                isExcluded:    true === ($patch['excluded'] ?? false),
                startsAt:      $instanceStart,
                endsAt:        self::endOf($instanceStart, $patch, $duration),
                title:         true === is_string($title) && '' !== $title ? $title : null,
            );
        }

        return $rest;
    }

    /**
     * An instance's end: its own duration when the patch states one, the series'
     * length when it does not.
     *
     * A duration that DateInterval refuses falls back rather than throwing. The
     * string comes from stored JSON that a remote or a hand edit wrote, and one
     * unreadable patch must not cost the push the rest of the series.
     *
     * @param array<string,mixed> $patch
     */
    private static function endOf(DateTimeImmutable $startsAt, array $patch, int $duration): DateTimeImmutable
    {
        if (true === is_string($patch['duration'] ?? null)) {
            try {
                return $startsAt->add(new \DateInterval($patch['duration']));
            } catch (\Exception) {
                // Falls through to the series' length below.
            }
        }

        return $startsAt->modify(sprintf('%+d seconds', $duration));
    }

    /**
     * A JSCalendar LocalDateTime as the UTC instant it names in this zone.
     *
     * Null for anything that is not one, so a hand-edited object cannot put
     * "tomorrow" on the wire as an instance's identity.
     *
     * The format is prefixed with `!` so every field the string does not state
     * is reset rather than taken from the current time. Without it the instant
     * carries this microsecond, and two instants that name the same second stop
     * comparing equal — which is exactly how an instance is matched to the
     * provider's answer.
     */
    private static function localInstant(string $local, DateTimeZone $zone): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(self::LOCAL_DATE_TIME, $local, $zone);

        return false === $parsed ? null : $parsed->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The zone a series' override keys are written in.
     *
     * The same fallback RecurrenceMaterialiser::zoneOf() makes, and it has to
     * be: a floating series is expanded in UTC, so a key resolved in the user's
     * zone instead would name an instance the expander never draws. Repeated
     * here rather than injected because this is a value object with no services
     * in it, and the alternative — giving every driver the materialiser so it
     * can ask — would hand a Doctrine-backed service to the one layer the
     * contract keeps Doctrine out of.
     */
    private static function zoneOf(CalendarEvent $event): DateTimeZone
    {
        if (null === $event->timeZone || '' === $event->timeZone) {
            return new DateTimeZone('UTC');
        }

        try {
            return new DateTimeZone($event->timeZone);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
