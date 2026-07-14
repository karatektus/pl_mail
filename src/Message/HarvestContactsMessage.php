<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched after a mailbox sync completes.
 * The handler harvests all from/to/cc/bcc addresses from the mailbox's
 * messages and upserts them into the contact table for the owning user.
 */
readonly class HarvestContactsMessage
{
    public function __construct(
        public int $mailboxId,
    ) {}
}
