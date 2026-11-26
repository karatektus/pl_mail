<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Job\JobState;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\DeleteAccountMessage;
use App\Repository\Job\BackgroundJob\BackgroundJobRepository;
use App\Repository\Mail\Account\AccountMailPurgeRepository;
use App\Repository\Mail\Account\AccountRepository;
use App\Service\Job\JobNotifier;
use App\Service\Mail\AccountCreator;
use App\Service\Push\PushSubscriptionRegistry;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Remove a mail account and everything synced for it, in batches, with the
 * jobs indicator counting along (#672). AccountRemoval queued it, after
 * switching the account's services off.
 *
 * ## The mail first, then the account
 *
 * The account row itself could go in one statement: every table that hangs
 * off it cascades. That one statement is what used to run inside the request,
 * and its cost is the mail — each removed message fires the thread-bin trigger
 * and the foreign-key checks of a dozen tables that point at messages. So the
 * mail goes first, a few hundred threads per transaction, through
 * AccountMailPurgeRepository (the statements "Purge local mail" uses, without
 * its JMAP change-log entries: the account is about to leave the apps' session
 * whole, and writing a destroyed entry per message for it would double the
 * work for nobody). What is left for the final DELETE — folders, labels,
 * calendars, address books — is small.
 *
 * Nothing here talks to a mail server. The mail stays on the server; the
 * account is only forgotten here.
 *
 * ## Failing
 *
 * Recorded on the job and not rethrown, as DeleteLabelHandler does: the person
 * is watching the indicator, and pressing Remove again is the retry. It
 * resumes cleanly, because each batch asks the database what is left rather
 * than working through a list made at the start. A lock collision with a sync
 * that was still finishing when the services went off is not a failure: the
 * batch is retried in place, RunBulkStatusHandler's way.
 */
#[AsMessageHandler]
final readonly class DeleteAccountHandler
{
    /**
     * Threads per transaction — LocalMailPurger's size, for its reason: one
     * transaction and one progress write per round, small enough that neither
     * holds locks for long.
     */
    private const int BATCH = 500;

    /** In-process attempts at a batch that lost a lock collision. */
    private const int LOCK_ATTEMPTS = 3;

    public function __construct(
        private BackgroundJobRepository    $jobs,
        private AccountRepository          $accounts,
        private AccountMailPurgeRepository $purge,
        private AccountCreator             $accountCreator,
        private PushSubscriptionRegistry   $pushRegistry,
        private JobNotifier                $notifier,
        private EntityManagerInterface     $em,
        private LoggerInterface            $logger,
    ) {
    }

    public function __invoke(DeleteAccountMessage $message): void
    {
        $job = $this->jobs->find($message->jobId);

        // Pruned, or redelivered after it finished.
        if (null === $job || false === $job->isActive()) {
            return;
        }

        $accountId = (int) ($job->view['accountId'] ?? 0);
        $account   = $this->accounts->find($accountId);

        // Gone already — removed with its user, or by an earlier run that died
        // after the last statement. What the job was for has happened.
        if (null === $account || $account->usr?->id !== $job->usr->id) {
            $this->finish($message->jobId, JobState::Done);

            return;
        }

        try {
            $removed = $this->removeMail($message->jobId, $accountId);

            $this->removeAccount($accountId);

            $this->logger->info('DeleteAccountHandler: account removed', [
                'accountId' => $accountId,
                'messages'  => $removed,
            ]);

            $this->finish($message->jobId, JobState::Done);
        } catch (Throwable $e) {
            $this->logger->error('DeleteAccountHandler: removing an account failed', [
                'jobId'     => $message->jobId,
                'accountId' => $accountId,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            $this->finish($message->jobId, JobState::Failed, $e->getMessage());
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @return int how many messages were removed
     */
    private function removeMail(int $jobId, int $accountId): int
    {
        $job = $this->jobs->find($jobId);

        if (null === $job) {
            return 0;
        }

        $job->begin($this->purge->countMessages($accountId));
        $this->em->flush();
        $this->notifier->changed($job);

        $removed = 0;

        while ([] !== ($threadIds = $this->purge->threadIdBatch($accountId, self::BATCH))) {
            $messageIds = $this->purge->messageIdsInThreads($threadIds);

            $this->withRetry(fn () => $this->purge->deleteThreadsAtomically($threadIds));

            $removed += count($messageIds);
            $this->advance($jobId, $removed);
        }

        // Messages caught between ingest and threading, which no thread batch
        // reaches. One statement each round, so atomic without help.
        while ([] !== ($messageIds = $this->purge->threadlessMessageIdBatch($accountId, self::BATCH))) {
            $this->withRetry(fn () => $this->purge->deleteMessages($messageIds));

            $removed += count($messageIds);
            $this->advance($jobId, $removed);
        }

        return $removed;
    }

    /**
     * The account row, with what is left hanging off it, and the list it
     * leaves behind put back in order.
     */
    private function removeAccount(int $accountId): void
    {
        $account = $this->accounts->find($accountId);

        if (null === $account) {
            return;
        }

        $this->releasePush($account);

        $user = $account->usr;

        $this->em->remove($account);
        $this->em->flush();

        if (null === $user) {
            return;
        }

        // What deleting used to do in the controller: no gap in the order, and
        // exactly one primary — usually settled already, when switching
        // sending off moved it, but not when this was the only account that
        // could send.
        $remaining = $this->accounts->findForUserOrdered($user);

        $this->accountCreator->resequence($remaining);
        $this->accountCreator->ensurePrimary($remaining);
        $this->em->flush();
    }

    /**
     * Hand back a JMAP account's push stream. Gmail's watch and Graph's
     * subscription went when mail was switched off; JMAP keeps its stream
     * through that, because calendar and contact changes arrive on it too, and
     * this is the last moment anything knows it exists.
     */
    private function releasePush(Account $account): void
    {
        if (false === $account->isJmap()) {
            return;
        }

        try {
            $this->pushRegistry->resolve($account)?->unsubscribe($account);
        } catch (Throwable $e) {
            // Best effort, as it always was on removal: a stream nobody answers
            // is dropped by the server.
            $this->logger->warning('DeleteAccountHandler: could not hand back push', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run one batch, again if the database shot it for a lock collision — see
     * RunBulkStatusHandler::applyWithRetry() for why that is not a failure.
     *
     * @param callable(): mixed $batch
     */
    private function withRetry(callable $batch): void
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                $batch();

                return;
            } catch (RetryableException $e) {
                if ($attempt >= self::LOCK_ATTEMPTS) {
                    throw $e;
                }

                $this->logger->warning('DeleteAccountHandler: lock collision, retrying the batch', [
                    'attempt' => $attempt,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Another batch is through. Through a fresh read and a clear, because the
     * rows just deleted would otherwise linger in the identity map batch after
     * batch.
     */
    private function advance(int $jobId, int $removed): void
    {
        $this->em->clear();

        $job = $this->jobs->find($jobId);

        if (null === $job) {
            return;
        }

        $job->advance($removed);
        $this->em->flush();
        $this->notifier->changed($job);
    }

    /**
     * End the job, through a fresh read: the batches clear the EntityManager,
     * so any job object held from before is detached.
     */
    private function finish(int $jobId, JobState $state, ?string $reason = null): void
    {
        if (false === $this->em->isOpen()) {
            $this->logger->error('DeleteAccountHandler: entity manager closed, the job could not be finished', [
                'jobId' => $jobId,
                'state' => $state->value,
            ]);

            return;
        }

        $job = $this->jobs->find($jobId);

        if (null === $job || false === $job->isActive()) {
            return;
        }

        $job->finish($state, $reason);
        $this->em->flush();
        $this->notifier->changed($job);
    }
}
