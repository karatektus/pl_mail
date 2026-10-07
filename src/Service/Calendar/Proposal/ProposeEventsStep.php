<?php

declare(strict_types=1);

namespace App\Service\Calendar\Proposal;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Interface\PostIngestStepInterface;
use App\Infrastructure\Messaging\Message\ProposeEventsMessage;
use App\Service\Mail\PostIngest\EnrichmentRouter;
use App\Service\Mail\PostIngest\RecentMailPolicy;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Looks for a date in prose in each freshly ingested message.
 *
 * A post-ingest step rather than a hook in the three sync paths, for the reason
 * the interface exists at all: a feature that reacts to new mail should be a
 * class, not three edits. And it belongs after ingest rather than inside it
 * because it depends on work the pipeline does in the same pass — the category
 * is assigned in that loop, and refusing bulk mail is this feature's first and
 * most important rule.
 *
 * IT USED TO DO ITS WORK INLINE, and argued for it at length here: the path
 * performs no I/O, the gate is a single regex, and queuing it would cost a
 * Messenger round trip to move microseconds off the ingest worker. All true of
 * one batch. What it left out is the mailbox import, where it is fifty messages
 * a batch for tens of thousands of messages on the one worker that fetches
 * mail — and the rule in PostIngestStepInterface has no exception for work
 * that is merely quick, because what it protects is that fetching mail is the
 * only thing the fetching worker does. So this dispatches like every other
 * step, and ProposeEventsHandler holds the loop.
 *
 * RECENT MAIL ONLY, which costs nothing: EventProposer already refuses a date
 * in the past, so an old message could only ever have been parsed in order to
 * be discarded. See RecentMailPolicy.
 */
final readonly class ProposeEventsStep implements PostIngestStepInterface
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

        $this->bus->dispatch(new ProposeEventsMessage($ids), $this->router->stampsFor($result));
    }
}
