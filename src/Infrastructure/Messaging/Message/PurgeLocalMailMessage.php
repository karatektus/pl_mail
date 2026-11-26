<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Remove one account's synced mail from this database, never from its server
 * (#575). Dispatched by Settings → Accounts; LocalMailPurger does the work.
 *
 * Queued rather than done in the request: a large mailbox is many batches,
 * each written to the JMAP change log, and a click should not wait on that.
 */
final readonly class PurgeLocalMailMessage
{
    public function __construct(
        public int $accountId,
    ) {}
}
