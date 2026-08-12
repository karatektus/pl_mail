<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * "Look at these freshly ingested messages for anything read-receipt shaped."
 *
 * Two jobs in one message because they are two readings of the same batch and
 * both are header work: flagging the messages whose senders asked for a
 * receipt, and recognising the messages that ARE receipts and matching them
 * back to what they are about.
 *
 * @see \App\Service\Mail\PostIngest\ReadReceiptStep for why this is queued
 *      rather than done inline.
 */
readonly class ProcessReadReceiptsMessage
{
    /**
     * @param list<int> $messageIds
     */
    public function __construct(public array $messageIds)
    {
    }
}
