<?php

declare(strict_types=1);

namespace App\Service\Mail\PostIngest;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Interface\PostIngestStepInterface;
use App\Infrastructure\Messaging\Message\ProcessReadReceiptsMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Notices read receipts in a freshly ingested batch — both directions.
 *
 * A step rather than a change to the three sync paths, because the headers it
 * needs are already on the row: Message::$headers is written by every provider
 * builder, normalised, so detection needs no new ingest code at all. What it
 * needs is a place to run once per batch, and that is what this interface is.
 *
 * Detection happens here rather than at read time on purpose. The alternative
 * — re-parsing the header bag whenever a message is opened — puts a decision
 * with security consequences on the hot path of every mailbox render, runs it
 * against a bag that could have been rewritten by a later sync, and repeats
 * the work forever for a fact that cannot change after delivery.
 *
 * Dispatches and returns, per the interface contract. The real work reads
 * message bodies and, for an inbound MDN, hunts the original message by
 * Message-ID — none of which belongs on a worker holding an IMAP connection.
 */
final readonly class ReadReceiptStep implements PostIngestStepInterface
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function afterCommit(PostIngestResult $result): void
    {
        $ids = [];

        foreach ($result->messages as $message) {
            $id = $message->id;

            if (null !== $id) {
                $ids[] = (int) $id;
            }
        }

        if ([] === $ids) {
            return;
        }

        $this->bus->dispatch(new ProcessReadReceiptsMessage($ids));
    }
}
