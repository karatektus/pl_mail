<?php

declare(strict_types=1);

namespace App\Domain\Enum\Ai;

/**
 * Why a backfill is not currently walking.
 *
 * Paused is the state an operator will see most, and "paused" on its own is
 * the least useful thing a panel can say: it invites a click on Resume for a
 * pause that is about to lift by itself, and it hides the two cases that
 * actually need somebody — a feature switched off underneath a run, and a host
 * that has stopped answering.
 *
 * Which is also the split that matters mechanically. Two of these keep the
 * chain alive on a delay and lift on their own; two of them ended the chain
 * and need a deliberate start.
 */
enum BackfillPauseReason: string
{
    /** Somebody pressed Pause. The chain is gone; Resume dispatches a new one. */
    case Operator = 'operator';

    /**
     * Somebody is using the AI right now, so the backfill stepped aside.
     *
     * The whole reason the yielding exists: backfill and the composer share one
     * integrated GPU, and a click that queues behind a batch is the "nothing
     * happens" complaint this work was done to remove. Lifts by itself a
     * cooldown after the last interactive request.
     */
    case Interactive = 'interactive';

    /** Semantic search was switched off mid-run. Nothing to resume into. */
    case FeatureOff = 'feature_off';

    /**
     * The model host answered nothing for a whole chunk.
     *
     * Retried on a long delay rather than failed outright: a host that is
     * rebooting comes back, and a backfill that gave up on the first blink
     * would have to be restarted by hand every time.
     */
    case HostUnreachable = 'host_unreachable';

    /** Does the queue still hold a delivery that will pick the walk back up? */
    public function resumesItself(): bool
    {
        return match ($this) {
            self::Interactive, self::HostUnreachable => true,
            self::Operator, self::FeatureOff         => false,
        };
    }
}
