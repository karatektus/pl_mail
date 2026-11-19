<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Domain\Enum\Job\JobState;
use App\Entity\Job\BackgroundJob;
use App\Repository\Job\BackgroundJob\BackgroundJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Writing a job's progress from a handler that clears the entity manager as it
 * goes (#672).
 *
 * Every step reads the job again by id rather than trusting an object held
 * from before: the handlers that use this clear the manager between chunks to
 * keep their flushes small, and a job held across a clear is detached — its
 * next flush would write nothing, and the indicator would sit at zero while the
 * work went on. Each write is flushed and announced at once, through
 * JobNotifier, so an open page moves with it.
 */
final readonly class JobProgress
{
    public function __construct(
        private BackgroundJobRepository $jobs,
        private JobNotifier             $notifier,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
    ) {
    }

    /** The job, while it still has work to do: null when it is gone or already over. */
    public function active(int $jobId): ?BackgroundJob
    {
        $job = $this->jobs->find($jobId);

        return null !== $job && true === $job->isActive() ? $job : null;
    }

    public function begin(int $jobId, int $total): void
    {
        $this->write($jobId, static fn (BackgroundJob $job) => $job->begin($total));
    }

    public function advance(int $jobId, int $processed): void
    {
        $this->write($jobId, static fn (BackgroundJob $job) => $job->advance($processed));
    }

    public function finish(int $jobId, JobState $state, ?string $reason = null): void
    {
        if (false === $this->em->isOpen()) {
            $this->logger->error('JobProgress: entity manager closed, the job could not be finished', [
                'jobId' => $jobId,
                'state' => $state->value,
            ]);

            return;
        }

        $this->write($jobId, static fn (BackgroundJob $job) => $job->finish($state, $reason));
    }

    /** @param callable(BackgroundJob): void $change */
    private function write(int $jobId, callable $change): void
    {
        $job = $this->active($jobId);

        if (null === $job) {
            return;
        }

        $change($job);
        $this->em->flush();
        $this->notifier->changed($job);
    }
}
