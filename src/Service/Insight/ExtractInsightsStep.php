<?php

declare(strict_types=1);

namespace App\Service\Insight;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Interface\PostIngestStepInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The insight pipeline's foothold in the sync: every freshly ingested message
 * is offered to the extractors, exactly the way ProposeEventsStep offers them
 * to the proposal detectors.
 *
 * The harvester already shields the batch per extractor and per message, so
 * the only job left here is the flush — once, after the batch, and only when
 * something was actually written. The pipeline's own catch shields the sync
 * from this step entirely; see PostIngestStepInterface.
 */
final readonly class ExtractInsightsStep implements PostIngestStepInterface
{
    public function __construct(
        private InsightHarvester $harvester,
        private EntityManagerInterface $em,
    ) {
    }

    public function afterCommit(PostIngestResult $result): void
    {
        $written = 0;

        foreach ($result->messages as $message) {
            $written += $this->harvester->harvest($message);
        }

        if (0 === $written) {
            return;
        }

        $this->em->flush();
    }
}
