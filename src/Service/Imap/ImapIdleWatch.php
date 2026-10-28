<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Infrastructure\ImapClient\ImapConnection;
use App\Infrastructure\ImapClient\ImapTask;

/**
 * What ImapIdleListener keeps about one folder it holds IDLE on.
 *
 * Outlives the connections it describes: a connection that drops is replaced
 * by the next one under the same watch, so the backoff, and a sync held back
 * by the debounce, carry over the reconnect.
 */
final class ImapIdleWatch
{
    public ?ImapTask $task = null;

    public ?ImapConnection $connection = null;

    public bool $stopRequested = false;

    /** microtime before which no new connection is made */
    public float $retryAt = 0.0;

    /** Seconds to wait after the next failure. */
    public int $backoff;

    public string $path = '';

    public string $account = '';

    public ?int $accountId = null;

    /** Unix time of the last heartbeat, to throttle the keepalive ones. */
    public int $lastBeatAt = 0;

    /** Unix time of the last sync dispatched. See ImapIdleListener::SYNC_MIN_GAP. */
    public int $lastSyncAt = 0;

    /** A sync asked for inside the quiet window and not yet sent. */
    public bool $syncPending = false;

    /** Whether the held sync also needs the full folder listing. */
    public bool $sweepPending = false;

    public function __construct(
        public readonly int $mailboxId,
        int $backoff,
    ) {
        $this->backoff = $backoff;
    }

    public function isRunning(): bool
    {
        return null !== $this->task && false === $this->task->isFinished;
    }
}
