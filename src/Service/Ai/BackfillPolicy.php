<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * How hard the backfill is allowed to push, and how quickly it gets out of the
 * way.
 *
 * WHY THE DEFAULTS ARE TIMID
 * ──────────────────────────
 * Backfill throughput does not matter. It is a one-off pass over old mail that
 * nobody is waiting on, it resumes correctly after any interruption, and taking
 * six hours instead of four costs nothing anybody notices. The composer feeling
 * responsive matters enormously, and the two want the same hardware: on the
 * target box that is one integrated GPU — an AMD Ryzen 7 5700G iGPU over
 * Vulkan, with about 31 GiB of GTT — shared by everything this application asks
 * a model to do.
 *
 * So a small batch and a real gap between batches, on purpose. A batch is the
 * longest a click can end up queued behind, and shortening it is worth far more
 * than the messages a second it costs.
 *
 * BOTH MODELS FIT AT ONCE, WHICH IS WHY THIS IS ABOUT QUEUEING AND NOT EVICTION
 * ────────────────────────────────────────────────────────────────────────────
 * The chat model measures 20.3 GiB resident and the embedding model about 1
 * GiB, against roughly 31 GiB of addressable GTT. With Ollama allowed to keep
 * more than one model loaded (OLLAMA_MAX_LOADED_MODELS above 1) they do not
 * evict each other, so a backfill does not cost the composer a thirteen-second
 * cold load — it costs whatever the host is already busy with when the click
 * arrives. That is the thing being managed here: not memory, but the queue in
 * front of an interactive request.
 *
 * CONFIGURABLE WITHOUT BEING IN .env
 * ──────────────────────────────────
 * Read through `%env(default:…)%` in config/services.yaml, so a deployment that
 * wants a faster pass sets AI_BACKFILL_BATCH_SIZE in its compose file and one
 * that has never heard of it gets these numbers. It is deliberately not one
 * more row in the installation reference: the answer for almost everybody is
 * "leave it alone", and the reason to change it — "my box is much bigger than
 * the one this was tuned on" — is not a decision made while installing.
 */
final readonly class BackfillPolicy
{
    /** Messages per delivery. */
    public int $batchSize;

    /** Milliseconds of quiet between one batch and the next. */
    public int $pauseMs;

    /**
     * How long after an interactive request the backfill keeps its hands off.
     *
     * Measured from the LAST sign of interactive work, so a person clicking
     * through several rewrites holds the pause open rather than opening a
     * window between clicks — which is exactly where a batch would slip in and
     * be in front of the next one.
     */
    public int $cooldownSeconds;

    /** How long to wait before trying a host that answered nothing. */
    public int $retrySeconds;

    /**
     * Chunks in a row that store nothing before the run gives up.
     *
     * One is a blink — a host restarting, a model being swapped. Five in a row,
     * with the retry delay between them, is several minutes of a host that is
     * not coming back, and continuing to knock is worse than saying so.
     */
    public int $maxEmptyChunks;

    public function __construct(
        int $batchSize,
        int $pauseMs,
        int $cooldownSeconds,
        int $retrySeconds,
        int $maxEmptyChunks = 5,
    ) {
        // Clamped rather than validated: these come from the environment, and a
        // typo in a compose file must not be able to stop the queue (a batch of
        // zero) or hold a worker for an hour (a delay of a million).
        $this->batchSize       = max(1, min(500, $batchSize));
        $this->pauseMs         = max(0, min(60_000, $pauseMs));
        $this->cooldownSeconds = max(0, min(3_600, $cooldownSeconds));
        $this->retrySeconds    = max(5, min(3_600, $retrySeconds));
        $this->maxEmptyChunks  = max(1, min(100, $maxEmptyChunks));
    }
}
