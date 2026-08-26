<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\Enum\Calendar\ParticipationStatus;
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
 *
 * The third is recurrenceOverrides, which is where a series stops being a rule.
 * An instance somebody moved is a patch filed under the LocalDateTime the rule
 * originally put it at, and both halves of it are read here: `start`, so it is
 * drawn on the day it went to, and `duration`, because an instance that moved is
 * routinely also a different length. `{"excluded": true}` takes it off the
 * calendar and a cancelled status keeps the row and strikes it through — the
 * answer to "wasn't there something today?" is more useful than a gap.
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
     * Drops every occurrence row for one event, committed or merely queued.
     *
     * Two steps, because they cover different rows. The DELETE clears what is
     * committed; remove() clears what this unit of work has queued but not yet
     * flushed — raw SQL cannot see those, and clearing the collection alone
     * does not unschedule their INSERT. Without the second, materialising twice
     * before a flush queues two rows with the same recurrence id and the unique
     * constraint rejects the pair.
     *
     * Public as well as being materialise()'s first act, because an event on
     * its way out of a synced calendar has to vanish from every view while its
     * row waits for the remote to be told — see
     * CalendarEventWriter::markLocallyDeleted().
     */
    public function clear(CalendarEvent $event): void
    {
        $this->occurrences->deleteForEvent($event);

        foreach ($event->occurrences as $existing) {
            $this->em->remove($existing);
        }

        $event->occurrences->clear();
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

        $this->clear($event);

        // An invitation nobody has accepted is not on the calendar yet.
        //
        // This is the whole of that rule, and it is here rather than as a
        // clause in each reader because occurrences are what every reader
        // reads: the views, the alert sweep, Happening Soon, a public share
        // link, an .ics export, a JMAP client. Writing no rows makes all of
        // them agree, and answering the invitation later re-materialises
        // through this same method — see InviteResponder, which is what puts
        // the meeting on the calendar the moment somebody says yes or maybe.
        //
        // The EVENT row is untouched, deliberately. The invite card above the
        // message still finds it, a later update from the organiser still
        // revises it, and declining is therefore reversible — none of which
        // would be true if the answer decided whether the row existed.
        //
        // $myParticipation is null for everything that is not an invitation
        // addressed to the owner, which is nearly everything; see the property.
        if (false === $this->isOnTheCalendar($event)) {
            $event->isRecurring     = false;
            $event->recurrenceUntil = $event->endsAt;

            return;
        }

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
     * Whether this event has earned a place in the calendar's views.
     *
     * Only an invitation addressed to the owner can answer false, and only
     * while it is unanswered or declined. "Maybe" counts as yes: a tentative
     * meeting is one you have to keep the slot for, and hiding it is how people
     * double-book.
     */
    private function isOnTheCalendar(CalendarEvent $event): bool
    {
        return match ($event->myParticipation) {
            null,
            ParticipationStatus::Accepted,
            ParticipationStatus::Tentative => true,
            default                        => false,
        };
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
                'eventId'   => $event->id,
                'rrule'     => $rrule,
                'error'     => $e->getMessage(),
                'exception' => $e,
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

            $override = $overrides[$this->converter->overrideKey($current, $zone)] ?? null;

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
     * Where one instance actually is, once its patch has been read.
     *
     * Both halves matter and only the first used to be read. An instance
     * somebody moved is routinely also a different length — a standup dragged
     * into the afternoon because it became the retro — and taking the series'
     * duration for it draws the right start with the wrong end, which is a
     * meeting that overlaps the one after it in every view.
     *
     * `start` is a LocalDateTime read in the event's own zone, and `duration` an
     * ISO 8601 duration (RFC 8984 §4.1.3). Either being unreadable falls back to
     * the series rather than refusing the instance: half a patch honoured is
     * still the instance on the day it was moved to.
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

        if (true === is_string($override['duration'] ?? null)) {
            $endsAt = $this->addDuration($startsAt, $override['duration']);

            if (null !== $endsAt) {
                return [$startsAt, $endsAt];
            }
        }

        return [$startsAt, $startsAt->modify(sprintf('+%d seconds', $duration))];
    }

    /**
     * Handed to DateInterval rather than parsed here: an ISO 8601 duration is a
     * grammar with months and weeks in it, and a hand-rolled reader of it would
     * be a second implementation that agrees until somebody sends "P1W".
     *
     * A duration that is not one falls back rather than throwing. The string
     * comes from a remote or from stored JSON, and one bad patch must not cost
     * the whole series its occurrences.
     */
    private function addDuration(DateTimeImmutable $startsAt, string $duration): ?DateTimeImmutable
    {
        try {
            return $startsAt->add(new \DateInterval($duration));
        } catch (\Exception) {
            return null;
        }
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

    private function parseLocal(string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($value, $zone))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * The zone this series is expanded in, and therefore the zone its override
     * keys are written in.
     *
     * All-day events are floating and carry no zone, so they expand in UTC —
     * which is what floating means here: the same wall-clock day everywhere.
     *
     * **Public because a producer of an override must not decide this for
     * itself.** The key is a LocalDateTime in the series' zone, and a producer
     * that fell back to the user's zone where the expander falls back to UTC
     * would file every patch on a floating event under a key that is never
     * looked up — an override that silently does nothing. EventInstanceEditor
     * asks here rather than repeating the fallback.
     */
    public function zoneOf(CalendarEvent $event): DateTimeZone
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
