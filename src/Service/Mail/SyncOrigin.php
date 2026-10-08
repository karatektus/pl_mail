<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\SyncTrigger;

/**
 * What started the sync that is running in this process right now.
 *
 * The sync handlers say so on the way in; ArrivalStamper reads it when a
 * message is stored, and the Gmail and Graph syncers read it to pass the same
 * answer on to the batches they queue.
 *
 * A SERVICE THAT REMEMBERS SOMETHING, which is not how the rest of this is
 * written, and the alternative was looked at: a parameter. It would have gone
 * through AccountSyncerInterface::sync(), each of its three implementations,
 * the IMAP syncer's mailbox loop and into three message builders — a dozen
 * signatures carrying a value that none of them use, to reach the one place
 * that writes it down. A worker handles one job at a time, so "the sync in
 * progress" is a well-defined thing to ask about, and during() puts back what
 * was there before, so a handler that throws cannot leave its trigger behind
 * for the next job.
 */
final class SyncOrigin
{
    private ?SyncTrigger $current = null;

    /**
     * @param callable(): void $work
     */
    public function during(?SyncTrigger $trigger, callable $work): void
    {
        $previous      = $this->current;
        $this->current = $trigger;

        try {
            $work();
        } finally {
            $this->current = $previous;
        }
    }

    /** Null outside a sync, and for a job queued before triggers were recorded. */
    public function current(): ?SyncTrigger
    {
        return $this->current;
    }
}
