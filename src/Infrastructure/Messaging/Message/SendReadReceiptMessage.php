<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * "Send the read receipt for this message, if policy still says so."
 *
 * Async for one reason and it is not throughput: opening a mailbox must never
 * block on an outbound SMTP conversation. The read transition that queues this
 * happens inside a request that is rendering a message to a person — a slow or
 * unreachable relay would turn "open a mail" into a thirty-second wait, and a
 * refusing one would turn it into an error page for a message that was read
 * perfectly well.
 *
 * Carries the id only, like SendMessageMessage. The handler re-reads the row
 * and re-runs the policy, so a message marked unread again, deleted, or
 * already answered between queue and delivery produces no receipt.
 */
readonly class SendReadReceiptMessage
{
    public function __construct(public int $messageId)
    {
    }
}
