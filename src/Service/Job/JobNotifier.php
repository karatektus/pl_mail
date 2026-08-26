<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Domain\Helper\ThrowableSeverity;
use App\Entity\Job\BackgroundJob;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tell an open page that a job has moved.
 *
 * Carries no state beyond "look again", like RuleRunNotifier and
 * CalendarNotifier: the job row is the record, and a page that missed a nudge
 * is one render behind rather than wrong. That is what makes it safe to publish
 * on every chunk without worrying about delivery.
 */
final readonly class JobNotifier
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function changed(BackgroundJob $job): void
    {
        $userId = $job->usr->id;

        if (null === $userId) {
            return;
        }

        try {
            $this->hub->publish(new Update(
                topics: [sprintf('mail/user/%d', $userId)],
                data: json_encode([
                    'type'  => 'jobs.changed',
                    'jobId' => $job->id,
                    'state' => $job->state->value,
                ]),
            ));
        } catch (\Throwable $e) {
            // A notification is the doorbell, not the delivery. The work this
            // announces is already committed, so throwing here cannot undo it —
            // it only fails a caller that succeeded, and Messenger then retries
            // work already done. A brief Mercure outage did exactly that to a
            // whole account sync. The cost of swallowing is a screen that waits
            // for its next refresh.
            $this->logger->log(
                ThrowableSeverity::level($e, LogLevel::WARNING),
                'JobNotifier: publish failed',
                ['error' => $e->getMessage(), 'exception' => $e],
            );
        }
    }
}
