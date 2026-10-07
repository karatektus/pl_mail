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
 *
 * RECENT MAIL ONLY. An invitation to a meeting held four years ago is not an
 * event anybody needs on a calendar, and for Gmail and Graph reading it means
 * fetching raw MIME from the provider — an API call per old invite during the
 * one sync where quota matters most. `app:backfill event-extraction` reads
 * history when somebody wants it. See RecentMailPolicy.
 */
final readonly class ExtractEventsStep implements PostIngestStepInterface
{
    public function __construct(
        private MessageBusInterface $bus,
        private RecentMailPolicy    $recent,
        private EnrichmentRouter    $router,
    ) {
    }

    public function afterCommit(PostIngestResult $result): void
    {
        $ids = $this->recent->recentIds($result);

        if ([] === $ids) {
            return;
        }

        $this->bus->dispatch(new ExtractEventsMessage($ids), $this->router->stampsFor($result));
    }
}
