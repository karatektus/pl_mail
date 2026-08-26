<?php

declare(strict_types=1);

namespace App\Domain\Enum\Ai;

/**
 * Where the embedding backfill has got to, as the panel reports it.
 *
 * Five states rather than a boolean, because "not running" is four different
 * situations and they call for four different things: nobody has ever started
 * one, somebody stopped it, it stopped itself because the host would not
 * answer, or there is nothing left to do. A single "stopped" would send an
 * operator looking for a fault in the one case where there is none.
 *
 * The status is STORED, and the progress behind it is not — see
 * {@see \App\Repository\Ai\AiBackfillStateRepository}. Counting embedded
 * messages is a query against the vectors themselves, so a killed worker
 * resumes rather than restarts; this column only records intent.
 */
enum BackfillStatus: string
{
    /** Never started, or started and finished long enough ago to be forgotten. */
    case Idle = 'idle';

    /** A chain of chunks is in the queue and moving. */
    case Running = 'running';

    /** Stopped on purpose. {@see BackfillPauseReason} says whose purpose. */
    case Paused = 'paused';

    /** Gave up: the host refused to answer for several chunks in a row. */
    case Failed = 'failed';

    /** Every mailbox has been walked to the end. */
    case Complete = 'complete';

    /**
     * Is there a chain of chunks still in flight?
     *
     * A pause that resumes itself keeps its delivery in the queue — delayed,
     * not cancelled — so it is still "going" as far as anything that wants to
     * avoid dispatching a second chain is concerned.
     */
    public function isLive(?BackfillPauseReason $reason): bool
    {
        return match ($this) {
            self::Running => true,
            self::Paused  => null !== $reason && $reason->resumesItself(),
            default       => false,
        };
    }
}
