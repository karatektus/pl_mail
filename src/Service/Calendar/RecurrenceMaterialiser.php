<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Repository\Calendar\CalendarEventOccurrenceRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Recur\RRuleIterator;

/**
 * Turns an event's recurrence rule into rows a calendar view can read.
 *
 * Runs on every event write. Rewrites that event's occurrences wholesale
 * rather than diffing them: the set is small, the write is one DELETE and one
 * batch of INSERTs, and a diff would be a second implementation of what the
 * rule means.
 *
 * Two things it must get right, and both are why this has its own test:
 *
 *   Expansion happens in the event's own timezone. A 09:00 Berlin standup is
 *   at 09:00 Berlin in November and 09:00 Berlin in July, which are different
 *   UTC instants — expanding in UTC would silently move the meeting an hour
 *   twice a year. So the seed is converted into the event's zone, iterated
 *   there, and each result converted back.
 *
 *   Unbounded rules are bounded anyway. FREQ=DAILY with no UNTIL has no last
 *   occurrence, so it is materialised to a horizon and rolled forward nightly.
 *   MAX_OCCURRENCES is the second belt: FREQ=SECONDLY inside the horizon is
 *   sixty million rows, and an .ics from a stranger is allowed to say that.
 */
final readonly class RecurrenceMaterialiser
{
    /**
     * How far either side of now occurrences are written. Past is short
     * because nobody scrolls back through a recurring series; future is long
     * enough that "next year" is instant.
     */
    public const string HORIZON_PAST = '-1 year';
    public const string HORIZON_FUTURE = '+2 years';

    /**
     * Hard cap per event, whatever the horizon says. Daily for two years is
     * ~730, so this leaves room for hourly-ish rules while making a hostile or
     * simply silly rule finite.
     */
    public const int MAX_OCCURRENCES = 1000;

    public function __construct(
        private CalendarEventOccurrenceRepository $occurrences,
        private RecurrenceRuleConverter           $converter,
        private EntityManagerInterface            $em,
        private LoggerInterface                   $logger,
    ) {
    }

    /**
     * Rewrites every occurrence row for one event.
     *
     * Also sets recurrenceUntil, which is what the nightly sweep reads to find
     * the events whose horizon needs extending. Null there means "no end".
     *
     * Does not flush — it joins the caller's unit of work, like StateManager.
     */
    public function materialise(CalendarEvent $event, ?DateTimeImmutable $now = null): void
    {
        if (null === $event->startsAt || null === $event->endsAt) {
            return;
        }

        $this->occurrences->deleteForEvent($event);
        $event->occurrences->clear();

        $rule = $this->firstRule($event);

        if (null === $rule) {
            $event->isRecurring     = false;
            $event->recurrenceUntil = $event->endsAt;

            $this->addOccurrence($event, $event->startsAt, $event->startsAt, $event->endsAt);

            return;
        }

        $event->isRecurring = true;

        $this->expand($event, $rule, $now ?? new DateTimeImmutable());
    }

    /**
     * @param array<string,mixed> $rule
     */
    private function expand(CalendarEvent $event, array $rule, DateTimeImmutable $now): void
    {
        $rrule = $this->converter->toRrule($rule);

        if (null === $rrule) {
            // An unusable rule is not a recurring event. One occurrence is
            // wrong-ish; a silently empty calendar is worse.
            $event->isRecurring     = false;
            $event->recurrenceUntil = $event->endsAt;

            $this->addOccurrence($event, $event->startsAt, $event->startsAt, $event->endsAt);

            return;
        }

        $zone     = $this->zoneOf($event);
        $seed     = $event->startsAt->setTimezone($zone);
        $duration = $event->endsAt->getTimestamp() - $event->startsAt->getTimestamp();

        $horizonEnd = $now->modify(self::HORIZON_FUTURE);
        $horizonMin = $now->modify(self::HORIZON_PAST);

        $overrides = $this->overrides($event);
        $written   = 0;
        $last      = null;
        $exhausted = true;
        $previousId = null;

        try {
            $iterator = new RRuleIterator($rrule, \DateTime::createFromInterface($seed));
        } catch (\Throwable $e) {
            $this->logger->warning('Calendar: unusable recurrence rule', [
                'eventId' => $event->id,
                'rrule'   => $rrule,
                'error'   => $e->getMessage(),
            ]);

            $event->isRecurring     = false;
            $event->recurrenceUntil = $event->endsAt;

            $this->addOccurrence($event, $event->startsAt, $event->startsAt, $event->endsAt);

            return;
        }

        foreach ($iterator as $current) {
            if ($written >= self::MAX_OCCURRENCES) {
                $exhausted = false;

                break;
            }

            $recurrenceId = DateTimeImmutable::createFromInterface($current)
                ->setTimezone(new DateTimeZone('UTC'));

            // A recurrence that does not move forward is not a recurrence. The
            // occurrence cap alone would turn that into a thousand identical
            // rows rather than a hang, which is the harder failure to notice —
            // so stop on the first repeat and say so.
            if (null !== $previousId && $recurrenceId <= $previousId) {
                $this->logger->warning('Calendar: recurrence rule does not advance, stopping', [
                    'eventId' => $event->id,
                    'rrule'   => $rrule,
                    'at'      => $recurrenceId->format(DATE_ATOM),
                ]);

                break;
            }

            $previousId = $recurrenceId;

            if ($recurrenceId > $horizonEnd) {
                $exhausted = false;

                break;
            }

            // The past horizon skips rather than stops: a rule that started in
            // 2019 still has to be walked to reach this year's instances.
            if ($recurrenceId < $horizonMin) {
                continue;
            }

            $override = $overrides[$this->overrideKey($current)] ?? null;

            if (true === ($override['excluded'] ?? false)) {
                continue;
            }

            [$startsAt, $endsAt] = $this->applyOverride($override, $recurrenceId, $duration, $zone);

            $this->addOccurrence(
                $event,
                $recurrenceId,
                $startsAt,
                $endsAt,
                isOverride: null !== $override,
                cancelled: 'cancelled' === ($override['status'] ?? null),
            );

            $last = $endsAt;
            $written++;
        }

        // Null means "we stopped because we ran out of room, not because the
        // rule did" — which is exactly the set the nightly sweep re-reads.
        $event->recurrenceUntil = true === $exhausted ? $last : null;
    }

    /**
     * An override may move the instance, so its start is taken from the patch
     * when present and derived from the recurrence id otherwise.
     *
     * @param array<string,mixed>|null $override
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function applyOverride(
        ?array            $override,
        DateTimeImmutable $recurrenceId,
        int               $duration,
        DateTimeZone      $zone,
    ): array {
        $startsAt = $recurrenceId;

        if (true === is_string($override['start'] ?? null)) {
            $moved = $this->parseLocal($override['start'], $zone);

            if (null !== $moved) {
                $startsAt = $moved;
            }
        }

        return [$startsAt, $startsAt->modify(sprintf('+%d seconds', $duration))];
    }

    private function addOccurrence(
        CalendarEvent     $event,
        DateTimeImmutable $recurrenceId,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        bool              $isOverride = false,
        bool              $cancelled = false,
    ): void {
        $occurrence               = new CalendarEventOccurrence();
        $occurrence->event        = $event;
        $occurrence->calendar     = $event->calendar;
        $occurrence->usr          = $event->usr;
        $occurrence->recurrenceId = $recurrenceId;
        $occurrence->startsAt     = $startsAt;
        $occurrence->endsAt       = $endsAt;
        $occurrence->isOverride   = $isOverride;
        $occurrence->cancelled    = $cancelled;

        $this->em->persist($occurrence);
        $event->occurrences->add($occurrence);
    }

    /**
     * JSCalendar allows several rules; RRULE allows one, and a second RRULE was
     * deprecated in RFC 5545 for good reason. The first is used and the rest
     * logged, rather than silently producing a union nobody asked for.
     *
     * @return array<string,mixed>|null
     */
    private function firstRule(CalendarEvent $event): ?array
    {
        $rules = $event->jscalendar['recurrenceRules'] ?? null;

        if (false === is_array($rules) || 0 === count($rules)) {
            return null;
        }

        if (count($rules) > 1) {
            $this->logger->info('Calendar: event has multiple recurrence rules, using the first', [
                'eventId' => $event->id,
                'count'   => count($rules),
            ]);
        }

        $first = reset($rules);

        return is_array($first) ? $first : null;
    }

    /**
     * recurrenceOverrides is keyed by the instance's original local start.
     *
     * @return array<string,array<string,mixed>>
     */
    private function overrides(CalendarEvent $event): array
    {
        $overrides = $event->jscalendar['recurrenceOverrides'] ?? null;

        if (false === is_array($overrides)) {
            return [];
        }

        $byKey = [];

        foreach ($overrides as $key => $patch) {
            if (true === is_string($key) && is_array($patch)) {
                $byKey[$key] = $patch;
            }
        }

        return $byKey;
    }

    /** The LocalDateTime form JSCalendar keys recurrenceOverrides by. */
    private function overrideKey(\DateTimeInterface $local): string
    {
        return $local->format('Y-m-d\TH:i:s');
    }

    private function parseLocal(string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($value, $zone))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * All-day events are floating and carry no zone, so they expand in UTC —
     * which is what floating means here: the same wall-clock day everywhere.
     */
    private function zoneOf(CalendarEvent $event): DateTimeZone
    {
        if (null === $event->timeZone || '' === $event->timeZone) {
            return new DateTimeZone('UTC');
        }

        try {
            return new DateTimeZone($event->timeZone);
        } catch (\Exception) {
            $this->logger->warning('Calendar: unknown time zone on event, falling back to UTC', [
                'eventId'  => $event->id,
                'timeZone' => $event->timeZone,
            ]);

            return new DateTimeZone('UTC');
        }
    }
}
