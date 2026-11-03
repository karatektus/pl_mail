<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\ArrivalPlace;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Repository\Mail\MessageThreadRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ends a hold: puts mail that was waiting for the assistant into its tab and
 * tells everybody looking.
 *
 * THREE CALLERS, ONE ENDING. ClassifyMailHandler releases a message when the
 * model has answered. ReleaseHeldMailHandler releases it when the wait ran out
 * first — the model is then still being asked, and its answer moves the mail
 * when it comes. The minute sweep releases whatever both of them missed. They differ
 * only in what category is on the message by the time they get here — the
 * verdict's, or the rules' — and everything after that is the same and is
 * this class, so that a release cannot be done three slightly different ways.
 *
 * WHAT A RELEASE IS, in order, and the order is load-bearing:
 *
 *   1. Stamp categoryReleasedAt, and flush. The thread resolution below is raw
 *      SQL that excludes held messages, so it has to be able to see that this
 *      one no longer is.
 *   2. Re-resolve the threads' categories — the step that was skipped when the
 *      message arrived. This is the moment the conversation appears in a tab.
 *   3. Record the change for JMAP clients, and flush that.
 *   4. Tell open pages to look again. After the flush, never before: a page
 *      that re-reads before the rows are committed draws the list it had.
 *
 * IDEMPOTENT, because the callers race by design: the timed release is queued
 * the moment a message is held and usually arrives to find it already
 * released. A message that is not held is skipped, and a call that releases
 * nothing announces nothing.
 */
final readonly class HeldMailReleaser
{
    public function __construct(
        private MessageThreadRepository $threads,
        private MailChangeRecorder      $changes,
        private NotifyScopes            $notifyScopes,
        private SyncNotifier            $notifier,
        private EntityManagerInterface  $em,
    ) {
    }

    /**
     * @param iterable<Message> $messages
     *
     * @return int how many were actually released
     */
    public function release(iterable $messages, ?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();

        $released = [];

        foreach ($messages as $message) {
            if (false === $message->isCategoryHeld()) {
                continue;
            }

            $message->categoryReleasedAt = $now;
            $released[]                  = $message;
        }

        if ([] === $released) {
            return 0;
        }

        $this->em->flush();

        $threadIds = [];

        foreach ($released as $message) {
            $threadId = $message->thread?->id;

            if (null !== $threadId) {
                $threadIds[(int) $threadId] = true;
            }
        }

        $this->threads->recomputeCategoriesForThreads(array_keys($threadIds));

        $this->announce($released);

        // The verdict is in, so "which tab" can finally be answered, and this
        // is the arrival the pipeline did not record (#369): held mail falls
        // under no switch while it waits. The pipeline's tests asked again,
        // because in the meantime it may have been read, filed or trashed.
        // Only live mail is ever held, so every message here is one the
        // pipeline would have announced; where it was fetched decides, as
        // there, whether its tab counts or only its labels.
        foreach ($released as $message) {
            if (null !== $message->seenAt || true === $message->isSetAside()) {
                continue;
            }

            $this->changes->mailArrived(
                (int) $message->account->id,
                $this->notifyScopes->forMessage(
                    $message,
                    inInbox: ArrivalPlace::Inbox === $message->arrivedIn && true === $message->isInInbox(),
                ),
            );
        }

        return count($released);
    }

    /**
     * Steps 3 and 4 on their own: record the change and tell open pages.
     *
     * Public for the one caller that moves held mail WITHOUT releasing it —
     * ClassifyMailHandler, when the assistant's answer arrives after the timed
     * release has already shown the message. The thread has just changed tab
     * under somebody's eyes, and nothing else would say so.
     *
     * The caller has already flushed and resolved the threads.
     *
     * @param iterable<Message> $messages
     */
    public function announce(iterable $messages): void
    {
        /** @var array<int, list<int>> $threadIdsByAccount */
        $threadIdsByAccount = [];
        /** @var array<int, Account> $accounts */
        $accounts = [];

        foreach ($messages as $message) {
            $account   = $message->account;
            $accountId = (int) $account->id;

            $accounts[$accountId] = $account;

            $this->changes->emailChanged($accountId, (string) $message->id, created: false, thread: null);

            $threadId = $message->thread?->id;

            if (null !== $threadId) {
                $threadIdsByAccount[$accountId][] = (int) $threadId;
            }
        }

        if ([] === $accounts) {
            return;
        }

        foreach ($threadIdsByAccount as $accountId => $ids) {
            $this->changes->threadsTouched($accountId, array_values(array_unique($ids)));
        }

        $this->em->flush();

        foreach ($accounts as $account) {
            $this->notifier->publishAccountSynced($account);
        }
    }
}
