<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Run one backfill an administrator queued on Admin → Backfills.
 *
 * Dispatched by BackfillRunner::queue(); the handler hands the id back to
 * BackfillRunner::execute(). The run's row carries what to run and over which
 * window, so the message carries only which row.
 */
readonly class RunBackfillMessage
{
    public function __construct(
        public int $runId,
    ) {}
}
