<?php

declare(strict_types=1);

namespace App\Service\Mail\PostIngest;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Interface\PostIngestStepInterface;
use App\Infrastructure\Messaging\Message\ExtractEventsMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues event extraction for a freshly ingested batch.
 *
 * Dispatches and returns, which is the whole contract a post-ingest step has:
 * this runs on a worker that is holding an IMAP connection or a Graph
 * rate-limit budget, and extraction can mean a parse, a disk read, or a fetch
 * of raw MIME. None of that belongs inside a sync.
 *
 * The first implementation of PostIngestStepInterface, and the reason it
 * exists — before it, this would have had to be wired into all three sync
 * paths by hand.
 */
final readonly class ExtractEventsStep implements PostIngestStepInterface
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

        $this->bus->dispatch(new ExtractEventsMessage($ids));
    }
}
