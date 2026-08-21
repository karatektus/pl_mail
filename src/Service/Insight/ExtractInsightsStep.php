<?php

declare(strict_types=1);

namespace App\Service\Insight;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Interface\PostIngestStepInterface;
use App\Entity\User\User;
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
 *
 * The Mercure announcement belongs here for the same reason the flush does:
 * this is the one place that knows whether the batch wrote anything, and for
 * whom. The harvester returns a count per message but has no notion of a
 * batch, and the step above has no notion of what was written — so a publish
 * anywhere else would either fire on a sync that changed nothing (waking every
 * open tab to re-request a fragment that comes back identical) or fire once
 * per insight, which for a nightly catch-up of thirty parcels is thirty
 * identical updates for one strip.
 */
final readonly class ExtractInsightsStep implements PostIngestStepInterface
{
    public function __construct(
        private InsightHarvester $harvester,
        private EntityManagerInterface $em,
        private InsightNotifier $notifier,
    ) {
    }

    public function afterCommit(PostIngestResult $result): void
    {
        $written = 0;

        /** @var array<int, User> $touched users whose strip has something new, keyed by id */
        $touched = [];

        foreach ($result->messages as $message) {
            $count = $this->harvester->harvest($message);

            if (0 === $count) {
                continue;
            }

            $written += $count;

            // Keyed by id, so a batch is one update per user however many of
            // their messages carried facts. A batch is usually one account and
            // therefore one user, but Gmail's handler ingests per account
            // inside a loop and nothing here should depend on that staying
            // true.
            $user = $message->account->usr;

            if ($user instanceof User) {
                $touched[$user->id] = $user;
            }
        }

        if (0 === $written) {
            return;
        }

        $this->em->flush();

        // After the flush, never before: the update tells tabs to come and
        // read, and a tab that reads before the rows are committed sees the
        // strip it had. The publish itself is not worth failing the sync over,
        // but it does not need its own catch — PostIngestStepInterface's
        // contract is that the pipeline swallows whatever this step throws,
        // and the rows are already durable by the time it can throw.
        foreach ($touched as $user) {
            $this->notifier->publishInsightsChanged($user);
        }
    }
}
