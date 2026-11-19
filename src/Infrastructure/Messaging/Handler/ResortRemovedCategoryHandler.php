<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Job\JobState;
use App\Infrastructure\Messaging\Message\ResortRemovedCategoryMessage;
use App\Service\Job\JobProgress;
use App\Service\Mail\InboxCategoryManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Put the conversations of a removed inbox category back into the tabs they
 * belong in, a batch at a time (#672). InboxCategoryManager::remove() took the
 * tab away in the request and left the list of conversations on the job.
 *
 * Batches clear the entity manager, because each writes a change record per
 * conversation that moved and those would otherwise pile up in it batch after
 * batch. Re-sorting is idempotent — it resolves each conversation from its
 * messages as they are now — so a redelivery, or a retry after a failure,
 * only repeats work and never undoes any.
 */
#[AsMessageHandler]
final readonly class ResortRemovedCategoryHandler
{
    /** Conversations per batch: one UPDATE and one flush of change records. */
    private const int BATCH = 500;

    public function __construct(
        private JobProgress            $progress,
        private InboxCategoryManager   $categories,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    public function __invoke(ResortRemovedCategoryMessage $message): void
    {
        $job = $this->progress->active($message->jobId);

        if (null === $job) {
            return;
        }

        // Read as mixed: the view's declared shape is the bulk actions' one,
        // and the list is this kind's own addition to it.
        /** @var mixed $listed */
        $listed    = $job->view['threadIds'] ?? [];
        $threadIds = is_array($listed) ? array_values(array_map(intval(...), $listed)) : [];

        try {
            $this->progress->begin($message->jobId, count($threadIds));

            $done = 0;

            foreach (array_chunk($threadIds, self::BATCH) as $batch) {
                $this->categories->resort($batch);
                $this->em->clear();

                $done += count($batch);
                $this->progress->advance($message->jobId, $done);
            }

            $this->progress->finish($message->jobId, JobState::Done);
        } catch (Throwable $e) {
            $this->logger->error('ResortRemovedCategoryHandler: the re-sort failed', [
                'jobId'     => $message->jobId,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            $this->progress->finish($message->jobId, JobState::Failed, $e->getMessage());
        }
    }
}
