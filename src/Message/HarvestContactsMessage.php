<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched after an account sync completes.
 * The handler harvests all from/to/cc/bcc addresses from the account's
 * messages and upserts them into the contact table for the owning user.
 *
 * Account-scoped (not mailbox-scoped): Gmail-API messages carry no mailbox
 * row, so a mailbox-based harvest never sees them.
 */
readonly class HarvestContactsMessage
{
    public function __construct(
        public int $accountId,
    ) {}
}
