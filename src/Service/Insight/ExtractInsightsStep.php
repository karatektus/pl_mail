<?php

declare(strict_types=1);

namespace App\Service\Insight;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Interface\PostIngestStepInterface;
use App\Infrastructure\Messaging\Message\ExtractInsightsMessage;
use App\Service\Mail\PostIngest\EnrichmentRouter;
use App\Service\Mail\PostIngest\RecentMailPolicy;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The insight pipeline's foothold in the sync: every recent, freshly ingested
 * message is queued for the extractors.
 *
 * IT USED TO RUN THEM HERE, inside the sync, and the case for that was that
 * the extractors read only what is already on the row. True, and beside the
 * point on the day a mailbox is imported: fifty messages a batch, every batch,
 * each offered to every extractor on the one worker that fetches mail. The
 * rule in PostIngestStepInterface — dispatch, do not work — has no exception
 * for work that is merely quick, because what it protects is that fetching
 * mail is the only thing the fetching worker does.
 *
 * RECENT MAIL ONLY. A parcel delivered in 2021 is not something to put on
 * somebody's radar, and that is what offering an imported mailbox to the
 * extractors produced. `app:backfill insights` reads history when somebody
 * wants it. See RecentMailPolicy.
 *
 * The work, the flush and the Mercure announcement are ExtractInsightsHandler's.
 */
final readonly class ExtractInsightsStep implements PostIngestStepInterface
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

        $this->bus->dispatch(new ExtractInsightsMessage($ids), $this->router->stampsFor($result));
    }
}
