<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Job\JobKind;
use App\Domain\Enum\Job\JobState;
use App\Entity\Job\BackgroundJob;
use App\Entity\Ai\AiFeature;
use App\Infrastructure\Messaging\Message\ClassifyMailMessage;
use App\Infrastructure\Messaging\Message\ReclassifyRecentMessage;
use App\Repository\Mail\MessageRepository;
use App\Repository\User\UserRepository;
use App\Service\Job\JobNotifier;
use App\Service\Ai\AiPermissions;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Hands the newest few hundred messages back to the classifier, forced.
 *
 * IT DECIDES WHICH MAIL AND REUSES THE REST. The asking is
 * ClassifyMailHandler's job and it already batches, already re-files what it
 * touched, and already refuses mail belonging to somebody who has switched
 * categorisation off. Doing any of that a second time here would be a second
 * implementation of the expensive half, and the two would drift.
 *
 * So this decides WHICH mail and hands it over a batch at a time, by CALLING
 * that handler rather than queueing for it: ClassifyMailMessage is routed to
 * the ingest transport, which is right for a message being classified as it
 * arrives and wrong for a few hundred of them at once — arriving mail would
 * queue behind a button press. Called directly, the work stays on the
 * maintenance transport this job already runs on.
 *
 * Batched anyway, so the flush and the re-file happen every twenty messages
 * instead of once at the end.
 *
 * THE PERMISSION IS CHECKED HERE TOO, even though the classifier checks it
 * again per message. The point is not safety — it is not walking a few hundred
 * messages for a mailbox whose owner has the feature switched off, each one to
 * be examined and discarded.
 */
#[AsMessageHandler]
final readonly class ReclassifyRecentHandler
{
    /**
     * Messages per envelope.
     *
     * Twenty model calls is a minute or two on a warm host. The batch is what
     * the flush and the thread re-file happen per, so a run that dies half way
     * leaves the messages it did get to already filed rather than all of it
     * pending.
     */
    private const int BATCH_SIZE = 20;

    public function __construct(
        private UserRepository         $users,
        private MessageRepository      $messages,
        private AiPermissions          $permissions,
        private ClassifyMailHandler    $classify,
        private JobNotifier            $jobs,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    public function __invoke(ReclassifyRecentMessage $message): void
    {
        $user = $this->users->find($message->userId);

        if (null === $user || false === $this->permissions->allows($user, AiFeature::Categorise)) {
            return;
        }

        $ids = $this->messages->findRecentIdsForUser($message->userId, $message->limit);

        // A JOB ROW, so this can be watched.
        //
        // It is the odd one out among the kinds — every other is a bulk action
        // over a mail view — and it is here because what a reader needs from a
        // run of model calls is exactly what the jobs indicator already gives:
        // is it running, how far has it got, did it fail. This ran fire-and-
        // forget first, which answered a request for a button with a button
        // whose only feedback was the page reloading.
        $job = new BackgroundJob($user, JobKind::Reclassify);

        $this->em->persist($job);
        $job->begin(count($ids));
        $this->em->flush();
        $this->jobs->changed($job);

        $done = 0;

        foreach (array_chunk($ids, self::BATCH_SIZE) as $batch) {
            // INVOKED, NOT DISPATCHED, and the transport is why.
            //
            // ClassifyMailMessage is routed to `ingest`, which is correct for
            // what it was written for — a message being classified is part of
            // that message arriving. It is wrong for this: a few hundred
            // messages is twenty-odd envelopes of model calls, and queueing
            // them on ingest puts everybody's arriving mail behind somebody's
            // button press for the best part of an hour.
            //
            // Calling the handler directly keeps the work on `maintenance`,
            // where this job already is and where long work belongs, and reuses
            // the asking, the batching, the per-message permission check and
            // the re-filing rather than growing a second copy of them.
            ($this->classify)(new ClassifyMailMessage(array_values($batch), true));

            // Per batch and not per message: the indicator moves in visible
            // steps, and a Mercure publish per model call would be twenty
            // notifications a minute saying almost the same thing.
            $done += count($batch);

            $job->advance($done);
            $this->em->flush();
            $this->jobs->changed($job);
        }

        $job->finish(JobState::Done);
        $this->em->flush();
        $this->jobs->changed($job);

        $this->logger->info('Recent mail asked about again', [
            'user'     => $message->userId,
            'messages' => count($ids),
        ]);
    }
}
