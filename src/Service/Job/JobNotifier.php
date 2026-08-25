<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Entity\Job\BackgroundJob;
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
    ) {
    }

    public function changed(BackgroundJob $job): void
    {
        $userId = $job->usr->id;

        if (null === $userId) {
            return;
        }

        $this->hub->publish(new Update(
            topics: [sprintf('mail/user/%d', $userId)],
            data: json_encode([
                'type'  => 'jobs.changed',
                'jobId' => $job->id,
                'state' => $job->state->value,
            ]),
        ));
    }
}
