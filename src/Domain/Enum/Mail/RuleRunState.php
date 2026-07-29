<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * Where a rule's "apply to existing mail" run has got to.
 *
 * Persisted rather than pushed, because the question a user asks is "is that
 * still going?" — and they ask it after a reload, on another device, or an hour
 * later. Mercure only nudges the UI to re-read this; it is never the record.
 */
enum RuleRunState: string
{
    /** Never run, or the last run has been acknowledged. */
    case Idle = 'idle';

    /** Dispatched, not yet picked up by a worker. */
    case Queued = 'queued';

    case Running = 'running';

    case Completed = 'completed';

    /** The worker gave up; the rule is unchanged and can be run again. */
    case Failed = 'failed';

    public function isBusy(): bool
    {
        return self::Queued === $this || self::Running === $this;
    }
}
