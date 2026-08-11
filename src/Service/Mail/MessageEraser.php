<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\RawMessageStorage;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Service\Label\ThreadLabelSynchronizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one way a message row leaves the database.
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
 *   3. the row, out of its thread's collection as well as out of the database,
 *      because everything below reads the collection;
 *   4. the thread's counters, recounted from what is left rather than
 *      decremented — the stored numbers drift, and a thread header claiming
 *      "3 messages" over two is the complaint that started all of this;
 *   5. the thread's labels, re-derived from the messages that remain.
 *
 * The caller flushes. Erasing a hundred vanished messages is one flush, not a
 * hundred, and the sweep that finds them is already inside a transaction it
 * would rather commit once.
 *
 * An emptied thread is left in place. It costs a row, it is what
 * ComposeController::discard() has always done, and the sync layer reuses it if
 * the conversation comes back.
 */
readonly class MessageEraser
{
    public function __construct(
        private EntityManagerInterface  $em,
        private MailChangeRecorder      $changes,
        private ThreadLabelSynchronizer $threadLabels,
        private AttachmentStorageHelper $attachments,
        private RawMessageStorage       $rawMessages,
    ) {
    }

    /**
     * Take one message out of the database, with everything that hangs off it.
     */
    public function erase(Message $message): void
    {
        $thread    = $message->thread;
        $accountId = (int) $message->account->id;
        $messageId = (string) $message->id;

        foreach ($message->messageParts as $part) {
            $this->attachments->delete($part->storagePath);
        }

        $this->rawMessages->delete($message->rawPath);

        // Ahead of the removal: a destroy names an id, and the id is gone the
        // moment Doctrine flushes the delete.
        $this->changes->emailDestroyed($accountId, $messageId, $thread);

        $this->em->remove($message);

        if (null === $thread) {
            return;
        }

        $thread->removeMessage($message);

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
