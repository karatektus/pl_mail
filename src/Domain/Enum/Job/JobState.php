<?php

declare(strict_types=1);

namespace App\Domain\Enum\Job;

/**
 * Where a background job has got to.
 *
 * Four states and no more. "Queued" and "running" are distinct because the gap
 * between them can be minutes on a busy worker and a person watching a progress
 * bar deserves to know which one they are looking at; "failed" is separate from
 * "done" because a job that stopped half-way has left the mailbox in a state
 * the user needs told about.
 */
enum JobState: string
{
    case Queued  = 'queued';
    case Running = 'running';
    case Done    = 'done';
    case Failed  = 'failed';

    /** Whether this job is still going to change something. */
    public function isActive(): bool
    {
        return self::Queued === $this || self::Running === $this;
    }
}
