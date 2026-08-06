<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Label\Label;
use App\Entity\Mail\MessageThread;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;

/**
 * How a change to somebody's mail is announced to their JMAP clients.
 *
 * StateManager is deliberately free of entity coupling — it takes an account
 * id, an object type and a string id, which is the right shape for the change
 * log and leaves every caller to remember the same two things: which object
 * type the thing it just wrote is, and that an Email which moved also moved the
 * Thread holding it. Five copies of that, and the ones that forgot the Thread
 * were not obviously wrong when read.
 *
 * There is deliberately no method per feature here. A draft being autosaved, a
 * file being attached and mail going out are the same announcement — an Email
 * changed, and so did its conversation — and naming them apart would promise a
 * distinction that the code does not make. Why a particular feature announces
 * what it does is written at the call site, where the reasoning belongs.
 *
 * Recording only persists (see StateManager::record()), which has two
 * consequences every caller inherits:
 *
 *   1. The ids must already exist, so these calls belong AFTER the flush that
 *      mints them — a message recorded before its insert announces id 0.
 *   2. Nothing here flushes. The log rows ride out on the caller's own flush,
 *      inside the same unit of work as the change they describe.
 */
final readonly class MailChangeRecorder
{
    public function __construct(
        private StateManager $stateManager,
    ) {
    }

    /**
     * An Email appeared or moved, and with it the conversation holding it —
     * every client list is sorted and summarised per thread, so announcing one
     * without the other leaves the conversation view stale.
     *
     * @param bool               $created true only for the write that minted the
     *                                    row. Announcing "created" for an id the
     *                                    client already holds is the one thing
     *                                    RFC 8620 §5.2 forbids, so an autosave
     *                                    of an existing draft passes false.
     * @param MessageThread|null $thread  null where the caller has no thread to
     *                                    announce yet: a thread created moments
     *                                    ago has no id until the next flush, and
     *                                    reading one early publishes id 0.
     */
    public function emailChanged(int $accountId, string $messageId, bool $created, ?MessageThread $thread): void
    {
        if (true === $created) {
            $this->stateManager->recordCreated($accountId, JmapObjectType::Email, $messageId);
        } else {
            $this->stateManager->recordUpdated($accountId, JmapObjectType::Email, $messageId);
        }

        if (null !== $thread) {
            $this->stateManager->recordThreadsTouched($accountId, [(int) $thread->id]);
        }
    }

    /**
     * An Email was really deleted — unlike Email/set destroy, which moves the
     * message to Trash and keeps its id resolvable.
     *
     * The id is passed rather than the entity because the caller has already
     * handed that to remove(): it is about to have none.
     *
     * Its conversation is announced as updated rather than destroyed even when
     * it is now empty, because the thread row is deliberately left standing —
     * so the id a client holds still resolves.
     */
    public function emailDestroyed(int $accountId, string $messageId, ?MessageThread $thread): void
    {
        $this->stateManager->recordDestroyed($accountId, JmapObjectType::Email, $messageId);

        if (null !== $thread) {
            $this->stateManager->recordThreadsTouched($accountId, [(int) $thread->id]);
        }
    }

    /**
     * The conversations a batch of mail moved, de-duplicated by StateManager.
     *
     * For callers that announce their Emails as they go and their Threads in
     * one pass afterwards, which is what threading a batch forces: the threads
     * only have ids once the batch has been flushed.
     *
     * @param iterable<int|string> $threadIds
     */
    public function threadsTouched(int $accountId, iterable $threadIds): void
    {
        $this->stateManager->recordThreadsTouched($accountId, $threadIds);
    }

    /**
     * A submission moved between the states EmailSubmission/get reports —
     * pending to final when the mail goes out, pending to canceled when it is
     * called off.
     *
     * A method of its own, where the Email announcements above deliberately
     * share one: this is a different OBJECT TYPE rather than a different
     * feature, and the distinction the class docblock refuses to make is
     * between features. A caller that announced the Email and not the
     * submission would leave a client showing "sending at 09:00" for mail that
     * left at 09:00, which is the whole reason the submission became gettable.
     *
     * The submission id is the Email id, which is why this takes a string
     * rather than anything submission-shaped: there is no submission row.
     */
    public function submissionChanged(int $accountId, string $submissionId): void
    {
        $this->stateManager->recordUpdated($accountId, JmapObjectType::EmailSubmission, $submissionId);
    }

    /**
     * A label was renamed, moved or hidden.
     *
     * One JMAP Mailbox change per account the label is bound to, because JMAP
     * state is tracked per account and a Mailbox id is a binding id. A label
     * with no bindings has no JMAP presence to update.
     */
    public function labelChanged(Label $label): void
    {
        foreach ($label->bindings as $binding) {
            $this->stateManager->recordUpdated(
                (int) $binding->account->id,
                JmapObjectType::Mailbox,
                (string) $binding->id,
            );
        }
    }

    /**
     * A label was deleted from every account at once, which is what the
     * unified sidebar offers. JMAP's Mailbox/set destroy is the per-account
     * operation and drops a single binding.
     *
     * Call this while the bindings are still readable — there is nothing to
     * iterate once the label row is gone.
     */
    public function labelDeleted(Label $label): void
    {
        foreach ($label->bindings as $binding) {
            $this->stateManager->recordDestroyed(
                (int) $binding->account->id,
                JmapObjectType::Mailbox,
                (string) $binding->id,
            );
        }
    }
}
