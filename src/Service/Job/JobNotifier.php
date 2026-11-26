<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Domain\Helper\ThrowableSeverity;
use App\Entity\Job\BackgroundJob;
use App\Entity\User\User;
use App\Infrastructure\Mercure\UserUpdate;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Mercure\HubInterface;
use Twig\Environment;

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
        private Environment $twig,
    ) {
    }

    /**
     * Tell a person's open pages to look at the background-work indicator
     * again, without a job to point at.
     *
     * For an import, which shows in the same indicator and is not a job row:
     * how far it has got is read off the account and its folders (see
     * ImportProgress), so there is nothing to name here but the reader.
     */
    public function nudge(User $user): void
    {
        if (null === $user->id) {
            return;
        }

        try {
            $this->hub->publish(UserUpdate::create(
                topics: [sprintf('mail/user/%d', $user->id)],
                data: json_encode(['type' => 'jobs.changed'], JSON_THROW_ON_ERROR),
            ));
        } catch (\Throwable $e) {
            // The doorbell, not the delivery — as for changed() below.
            $this->logger->log(
                ThrowableSeverity::level($e, LogLevel::WARNING),
                'JobNotifier: publish failed',
                ['error' => $e->getMessage(), 'exception' => $e],
            );
        }
    }

    public function changed(BackgroundJob $job): void
    {
        $userId = $job->usr->id;

        if (null === $userId) {
            return;
        }

        try {
            $this->hub->publish(UserUpdate::create(
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

    /**
     * Say how a job ended, as a toast on the person's open pages (#672).
     *
     * For the jobs whose result is a sentence — "12 added, 3 updated" — that
     * used to be the answer to the request itself. A finished job leaves the
     * indicator at once (BackgroundJobRepository::findVisibleForUser), so
     * without this the outcome of an import would be nowhere at all. Rendered
     * here, as SendOutcomeNotifier renders its own, so the toast is
     * _toast.stream.html.twig and not a copy of it in JavaScript.
     *
     * @param string $message already translated, in the person's language
     * @param string $type    success | error | info
     */
    public function outcome(User $user, string $message, string $type = 'success'): void
    {
        if (null === $user->id) {
            return;
        }

        try {
            $this->hub->publish(UserUpdate::create(
                topics: [sprintf('mail/user/%d', $user->id)],
                data: json_encode([
                    'type'   => 'job.outcome',
                    'stream' => $this->twig->render('_partials/_toast.stream.html.twig', [
                        'message' => $message,
                        'type'    => $type,
                    ]),
                ], JSON_THROW_ON_ERROR),
            ));
        } catch (\Throwable $e) {
            // The doorbell, not the delivery — as for changed() above.
            $this->logger->log(
                ThrowableSeverity::level($e, LogLevel::WARNING),
                'JobNotifier: outcome publish failed',
                ['error' => $e->getMessage(), 'exception' => $e],
            );
        }
    }
}
