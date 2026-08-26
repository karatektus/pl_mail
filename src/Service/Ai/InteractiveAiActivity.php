<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Repository\Ai\AiBackfillStateRepository;
use App\Repository\Ai\AiCallMetricRepository;
use DateTimeImmutable;

/**
 * Is a person waiting on the model right now, or were they a moment ago?
 *
 * The question the backfill asks before every batch. It exists because the
 * backfill and the composer share one GPU: a click that lands while a batch is
 * in flight waits for that batch, and "I pressed the button and nothing
 * happened" is the complaint the whole yielding arrangement was built to
 * remove.
 *
 * TWO SIGNALS, BECAUSE ONE OF THEM IS ALWAYS LATE
 * ───────────────────────────────────────────────
 *  · ai_call_metric records a call when it FINISHES. That is the honest record
 *    of what the host has been asked to do, and it covers every caller — the
 *    JMAP clients included — but a streamed draft can run for half a minute
 *    before it produces a row, which is the entire window that matters.
 *  · ai_backfill_state.interactive_seen_at is stamped when an interactive
 *    request STARTS, by a listener in the web process. That covers the request
 *    still in flight, and it is in the database rather than in a cache because
 *    the process that writes it and the worker that reads it are different
 *    containers with nothing else in common that survives a restart.
 *
 * The newer of the two is the answer. Neither alone is enough and both are
 * cheap: one indexed MAX over a bounded window, one single-row read.
 *
 * DELIBERATELY GENEROUS
 * ─────────────────────
 * A search that never touched a model still counts as interactive work, and a
 * cooldown measured in a minute and a half is far longer than any single
 * request. Both are the safe direction to be wrong in: the cost of pausing a
 * backfill that did not need pausing is a slower pass over old mail, and the
 * cost of not pausing one is the thing this is here to fix.
 */
final readonly class InteractiveAiActivity
{
    /**
     * How far back the metrics half of the signal looks.
     *
     * Ten minutes is comfortably past any cooldown anybody would configure, and
     * it is what keeps the query on the index instead of walking the table back
     * to the day the feature was switched on.
     */
    private const int LOOKBACK_SECONDS = 600;

    public function __construct(
        private AiBackfillStateRepository $state,
        private AiCallMetricRepository    $metrics,
    ) {
    }

    /** An interactive request has just begun. Called from the web process. */
    public function touch(?DateTimeImmutable $now = null): void
    {
        $this->state->touchInteractive($now ?? new DateTimeImmutable());
    }

    /** The newest of the two signals, or null when nothing recent happened. */
    public function lastSeen(?DateTimeImmutable $now = null): ?DateTimeImmutable
    {
        $now = $now ?? new DateTimeImmutable();

        $stamped = $this->state->current()->interactiveSeenAt;
        $recorded = $this->metrics->lastInteractiveCallAt(
            $now->modify('-' . self::LOOKBACK_SECONDS . ' seconds'),
        );

        if (null === $stamped) {
            return $recorded;
        }

        if (null === $recorded) {
            return $stamped;
        }

        return $stamped > $recorded ? $stamped : $recorded;
    }

    /**
     * Should the backfill keep its hands off for now?
     *
     * A cooldown of zero switches the yielding off entirely, which is a
     * legitimate thing to want on a box where the model host is somewhere else
     * and nothing is being shared.
     */
    public function shouldYield(int $cooldownSeconds, ?DateTimeImmutable $now = null): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        $now  = $now ?? new DateTimeImmutable();
        $seen = $this->lastSeen($now);

        if (null === $seen) {
            return false;
        }

        return $seen->getTimestamp() > $now->getTimestamp() - $cooldownSeconds;
    }

    /**
     * Seconds until the cooldown lifts, at least one.
     *
     * What the yielding batch waits before trying again. Never zero: a delay of
     * zero is a message that comes straight back, finds the cooldown has not
     * quite lifted, and re-dispatches itself in a tight loop through the
     * transport.
     */
    public function secondsUntilQuiet(int $cooldownSeconds, ?DateTimeImmutable $now = null): int
    {
        $now  = $now ?? new DateTimeImmutable();
        $seen = $this->lastSeen($now);

        if (null === $seen) {
            return 1;
        }

        return max(1, $seen->getTimestamp() + $cooldownSeconds - $now->getTimestamp());
    }
}
