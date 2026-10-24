<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\RawMessageStorage;
use App\Domain\Helper\SealedMessageStorage;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Service\Label\ThreadLabelSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The one way a message row leaves the database.
 *
 * Whoever decided it should: the server that no longer has it (the syncers),
 * a draft discarded in the composer, or the user deleting it for good, where
 * MessagePurger tells the provider first and then hands the row to this.
 *
 * There were two before this, written months apart and agreeing on nothing.
 * ComposeController::discard() deleted a draft's attachment files, recounted
 * its thread and announced a destroy; SentCopyReconciler::discard() recounted
 * the thread and announced a destroy but left the files on disk, which was
 * survivable only because the rows it removed were duplicates whose files
 * another row still pointed at. Neither touched rawPath, and neither
 * re-synchronised the thread's labels.
 *
 * Remote deletion is what made that gap matter. A message the server no longer
 * has is not a duplicate of anything: it is the only row that named its bytes,
 * and deleting it while leaving the .eml and the attachments behind grows the
 * storage directory forever with files nothing can ever reach again. It is also
 * the only row that carried its labels into the thread, so a thread that loses
 * its last Inbox message has to stop wearing the Inbox label or it keeps
 * showing in a list none of its messages are in.
 *
 * So all of it happens here, in the order the pieces depend on each other:
 *
 *   1. the files, while the row still names them;
 *   2. the destroy announcement, while the id still exists — StateManager only
 *      persists change rows, so they ride out on the caller's flush;
 *   3. the parts and the row, out of its thread's collection as well as out of
 *      the database, because everything below reads the collection;
 *   4. the thread's counters, recounted from what is left rather than
 *      decremented — the stored numbers drift, and a thread header claiming
 *      "3 messages" over two is the complaint that started all of this;
 *   5. the thread's labels, re-derived from the messages that remain.
 *
 * The caller flushes. Erasing a hundred vanished messages is one flush, not a
 * hundred, and the sweep that finds them is already inside a transaction it
 * would rather commit once.
 *
 * A thread the erase empties goes with it (#299). It used to be left in place
 * for the sync to reuse if the conversation came back, but an empty thread is a
 * list row with a subject, no participants and a count of zero, which nothing
 * in the app knows how to render, and MessagePurger was already removing them.
 * The sync starts a new thread for a conversation that comes back, and every
 * table that points at a thread goes with it by cascade or lets go by SET NULL.
 */
readonly class MessageEraser
{
    public function __construct(
        private EntityManagerInterface  $em,
        private MailChangeRecorder      $changes,
        private ThreadLabelSynchronizer $threadLabels,
        private AttachmentStorageHelper $attachments,
        private RawMessageStorage       $rawMessages,
        private SealedMessageStorage    $sealed,
        private LoggerInterface         $logger,
    ) {
    }

    /**
     * Take one message out of the database, with everything that hangs off it.
     */
    public function erase(Message $message): void
    {
        $thread = $message->thread;

        $accountId = $message->account->id;
        $messageId = $message->id;

        $this->removeFiles($message);

        if (null !== $message->id) {
            $this->sealed->delete($message->id, SealedMessageStorage::DRAFT);
            $this->sealed->delete($message->id, SealedMessageStorage::OUTGOING);
            $this->sealed->delete($message->id, SealedMessageStorage::DECRYPTED);
        }

        // Ahead of the removal: a destroy names an id, and the id is gone the
        // moment Doctrine flushes the delete. A row that was never written has
        // no id a client could hold, and nothing to announce.
        if (null !== $accountId && null !== $messageId) {
            $this->changes->emailDestroyed($accountId, (string) $messageId, $thread);
        }

        // The parts explicitly, and this is not tidiness (#299). The loop in
        // removeFiles() has just loaded every one of them into the unit of
        // work, and Message::$messageParts has neither cascade nor
        // orphanRemoval, so removing the message alone left them managed. The
        // database takes their rows with the message's by ON DELETE CASCADE,
        // but Doctrine is not told: after the delete it forgets the message and
        // nulls its id, and the next flush in the same unit of work found
        // managed parts pointing at an entity it no longer knew — "a new entity
        // was found through the relationship MessagePart#message". Every sync
        // that erased mail with an attachment failed on its following flush.
        //
        // Not fixed with cascade on the mapping, for the reason MessagePurger
        // gave when it hit the same error first: that changes every other path
        // that touches a message, the syncers that detach and re-attach parts
        // among them, and this is the one place that wants a part destroyed.
        foreach ($message->messageParts as $part) {
            $this->em->remove($part);
        }

        $this->em->remove($message);

        if (null === $thread) {
            return;
        }

        $thread->removeMessage($message);

        if (0 === $thread->messages->count()) {
            $this->removeThread($thread, $accountId);

            return;
        }

        $this->recount($thread);
        $this->threadLabels->sync($thread);
    }

    /**
     * @param iterable<Message> $messages
     *
     * @return int how many rows were erased
     */
    public function eraseAll(iterable $messages): int
    {
        $erased = 0;

        foreach ($messages as $message) {
            $this->erase($message);
            ++$erased;
        }

        return $erased;
    }

    /**
     * The bytes on disk, which the database rows do not take with them.
     *
     * Failures are logged and swallowed rather than aborting the erase, as
     * MessagePurger always did. A file that has already gone, or a volume that
     * is momentarily unwritable, must not keep a row the server no longer has
     * or the user asked to destroy: the row is the part anyone can see, and a
     * leftover file is a cleanup problem rather than a correctness one.
     */
    private function removeFiles(Message $message): void
    {
        foreach ($message->messageParts as $part) {
            try {
                $this->attachments->delete($part->storagePath);
            } catch (\Throwable $e) {
                $this->logger->warning('MessageEraser: could not delete an attachment', [
                    'part'      => $part->id,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        try {
            $this->rawMessages->delete($message->rawPath);
        } catch (\Throwable $e) {
            $this->logger->warning('MessageEraser: could not delete a raw source', [
                'message'   => $message->id,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * A conversation whose last message has just gone.
     *
     * Announced as destroyed, the way ThreadMerger announces the thread it
     * absorbs: emailDestroyed() has already called it updated, and a JMAP
     * client that keeps an id which no longer resolves shows a conversation it
     * can never open. Read before the flush, which takes the id away.
     */
    private function removeThread(MessageThread $thread, ?int $accountId): void
    {
        if (null !== $accountId && null !== $thread->id) {
            $this->changes->threadDestroyed($accountId, $thread->id);
        }

        $this->em->remove($thread);
    }

    /**
     * Every counter the thread publishes, taken from the messages it still
     * holds. Never decremented — see the class docblock.
     */
    private function recount(MessageThread $thread): void
    {
        $unread          = 0;
        $withAttachments = 0;

        foreach ($thread->messages as $remaining) {
            if (null === $remaining->seenAt) {
                ++$unread;
            }

            if (true === $remaining->hasAttachments) {
                ++$withAttachments;
            }
        }

        $thread->messageCount    = $thread->messages->count();
        $thread->unreadCount     = $unread;
        $thread->attachmentCount = $withAttachments;
    }
}
