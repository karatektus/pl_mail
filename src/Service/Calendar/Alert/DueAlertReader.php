<?php

declare(strict_types=1);

namespace App\Service\Calendar\Alert;

use App\Domain\DTO\Calendar\DueAlert;
use App\Repository\Calendar\CalendarEventOccurrenceRepository;
use DateTimeImmutable;

/**
 * Which alerts are due right now.
 *
 * The schedule half of the feature: it decides what has come due and nothing
 * else — AlertDeliverer decides whether it has already been sent and where it
 * goes. Split because the two answer different questions and only this one has
 * to be right about time.
 *
 * ── The window, and why an alert has a floor under it ─────────────────────
 *
 * An alert is due when its trigger falls in the half-open interval
 * `(now - LOOKBACK, now]`. Both ends matter:
 *
 *   The upper end is obvious — a trigger in the future has not happened.
 *
 *   **The lower end is the floor, and it is what stops turning this feature on
 *   from delivering a year of history.** Without it, the first sweep after an
 *   install upgrade, or after `app:backfill events` imports twelve months of
 *   flights and hotel bookings, would find every alert on every past occurrence
 *   unsent and send all of them. There is no "already delivered" record to
 *   suppress that, because the records only start existing once this runs. A
 *   floor needs no state at all: an alert whose trigger passed more than an hour
 *   ago is not delivered, and never will be, whatever the table says.
 *
 * An hour, against a sweep that runs every minute, is sixty times the headroom
 * the schedule needs. It covers a worker restart, a deploy, a long-running
 * migration and a machine that was asleep for a while; it does not cover a
 * weekend of downtime, and it should not — "your meeting starts in ten minutes"
 * is not a true statement on Monday.
 *
 * ── The candidate window is a different, wider thing ──────────────────────
 *
 * SQL cannot add an offset stored in jsonb to a timestamp, so the query fetches
 * occurrences whose START is near enough that some honoured offset could put a
 * trigger inside the window above, and the arithmetic happens here. MAX_LEAD is
 * therefore also a limit: an alert set more than thirty-one days before an event
 * is stored, round-trips and never fires. That is deliberate — the alternative
 * is a candidate query with no upper bound on how far ahead it reads, which on a
 * calendar with a decade of birthdays in it is a scan of the whole table every
 * minute. MAX_TRAIL is the same bound in the other direction and covers alerts
 * relative to the END of an event; an event lasting longer than a day does not
 * get its end-relative alert.
 *
 * ── What one occurrence means ─────────────────────────────────────────────
 *
 * A recurring event produces one alert per occurrence, not one per series,
 * because the candidates are occurrence rows — the same rows every calendar view
 * reads. That also settles overrides for free: RecurrenceMaterialiser has
 * already applied `recurrenceOverrides` by the time a row exists, so an instance
 * somebody dragged to Thursday carries Thursday's `startsAt` and alerts about
 * Thursday, and one they excluded has no row at all. An instance they cancelled
 * keeps its row and is filtered out in SQL. **None of that is re-implemented
 * here, and it must not be** — a second reading of the overrides map would be a
 * second opinion about where a meeting is.
 *
 * The materialiser's horizon is therefore this reader's horizon too: an
 * occurrence beyond it has no row, so it has no alert, and the nightly sweep
 * that rolls the horizon forward is what makes next year's standup alertable.
 *
 * An AbsoluteTrigger on a RECURRING event is deliberately not honoured. One
 * instant cannot mean each of a hundred occurrences, and picking one of them to
 * mean would be inventing an answer RFC 8984 does not give. On a one-off event
 * it is honoured normally, which is the only shape anything actually produces.
 */
final readonly class DueAlertReader
{
    /**
     * How late an alert may still be delivered — and therefore how much of the
     * past a first run, or a run after downtime, is allowed to see.
     *
     * CalendarAlertsCommand's prune cutoff must stay well beyond this: a
     * delivery record dropped while its trigger is still inside this window
     * would let the alert be claimed again.
     */
    public const string LOOKBACK = '-1 hour';

    /** The furthest ahead of an occurrence an alert is honoured. */
    private const string MAX_LEAD = '+31 days';

    /**
     * The furthest behind an occurrence's start a trigger may still be — an
     * alert relative to the end of an event, or one set to go off after it
     * starts.
     */
    private const string MAX_TRAIL = '-1 day';

    /**
     * Occurrences examined per sweep. Whatever is left waits a minute, and the
     * lookback window is an hour, so nothing is lost by stopping early.
     */
    private const int BATCH = 500;

    public function __construct(
        private CalendarEventOccurrenceRepository $occurrences,
        private AlertReader                       $alerts,
    ) {
    }

    /**
     * @return list<DueAlert> oldest trigger first, so a backlog is delivered in
     *                        the order it accumulated
     */
    public function due(?DateTimeImmutable $now = null): array
    {
        $now   = $now ?? new DateTimeImmutable();
        $floor = $now->modify(self::LOOKBACK);

        $candidates = $this->occurrences->findAlertCandidates(
            $floor->modify(self::MAX_TRAIL),
            $now->modify(self::MAX_LEAD),
            self::BATCH,
        );

        $due = [];

        foreach ($candidates as $occurrence) {
            $event = $occurrence->event;
            $user  = $occurrence->usr;

            // Every one of these is non-nullable in the database and nullable in
            // the mapping, so the guard is about the type system rather than
            // about a row that could exist. Skipping keeps this loop total.
            if (
                null === $event || null === $user
                || null === $event->id || null === $user->id
                || null === $occurrence->recurrenceId
                || null === $occurrence->startsAt || null === $occurrence->endsAt
            ) {
                continue;
            }

            foreach ($this->alerts->alertsOf($event) as $alert) {
                // See the class docblock: an absolute instant cannot address one
                // of a hundred occurrences, so it is honoured only where there
                // is exactly one to address.
                if (null !== $alert->absoluteAt && true === $event->isRecurring) {
                    continue;
                }

                $triggerAt = $alert->triggerFor($occurrence->startsAt, $occurrence->endsAt);

                if (null === $triggerAt || $triggerAt <= $floor || $triggerAt > $now) {
                    continue;
                }

                $due[] = new DueAlert(
                    event:        $event,
                    user:         $user,
                    eventId:      $event->id,
                    userId:       $user->id,
                    alert:        $alert,
                    recurrenceId: $occurrence->recurrenceId,
                    startsAt:     $occurrence->startsAt,
                    endsAt:       $occurrence->endsAt,
                    triggerAt:    $triggerAt,
                );
            }
        }

        usort($due, static fn (DueAlert $a, DueAlert $b): int => $a->triggerAt <=> $b->triggerAt);

        return $due;
    }
}
