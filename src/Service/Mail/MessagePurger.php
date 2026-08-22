<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\RawMessageStorage;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Infrastructure\Messaging\Message\PurgeRemoteMessagesMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Psr\Log\LoggerInterface;

/**
 * Deleting mail for good — the only operation in plMail that destroys
 * something rather than moving it.
 *
 * Everything else here is a label change wearing a verb. Archive removes Inbox,
 * trash adds Trash, and every one of them is undoable by adding the label back.
 * This one is not, which is why it exists as its own service with its own name
 * rather than as another method on ThreadStatusUpdater: nothing should reach
 * this by accident, and a reader of a controller should be able to see which of
 * the two kinds of delete they are looking at.
 *
 * ## Why this had to be written rather than wired up
 *
 * Most of it was already here and none of it was connected.
 * LabelChangePropagator::delete() existed and had no callers;
 * ApplyImapFlagsHandler already implemented `'delete' => $imapMessage->delete(
 * expunge: true)` and carried a comment saying the row was "on its way to
 * having no row at all"; MailChangeRecorder::emailDestroyed() was written and
 * unused. What was missing was the part that actually removes the local copy,
 * and without it the UI could only offer a Delete that moved things to the bin
 * — including for mail that was already in the bin, which is how a report of
 * "deleting a mail in the trash makes it vanish from the list and stay in the
 * trash" arrives.
 *
 * ## Order, which is the only subtle thing here
 *
 * The provider goes first, while the row still knows where the message lives:
 * the IMAP UID, the Gmail id, the Graph id. Delete the row first and there is
 * nothing left to name the message with, so it stays on the server forever and
 * comes back on the next sync — the bug this ordering exists to prevent, and
 * the reason the propagator call is not at the end where it would read more
 * naturally.
 *
 * The dispatch is asynchronous (it goes onto the export queue), so a provider
 * that is unreachable does not stop the local delete. That is deliberate: the
 * alternative is refusing to delete anything while an account is offline, and
 * the queue retries.
 *
 * ## What "for good" includes
 *
 * The row, its parts, the attachment files, and the raw source. Leaving the
 * files behind would make the deletion a lie in the way that matters most —
 * the bytes of the mail would still be on the disk, which for a mail client is
 * the thing a person deleting a message is usually trying to prevent.
 */
final readonly class MessagePurger
{
    public function __construct(
        private EntityManagerInterface  $entityManager,
        private MessageBusInterface     $bus,
        private MailChangeRecorder      $changes,
        private AttachmentStorageHelper $attachments,
        private RawMessageStorage       $raw,
        private LoggerInterface         $logger,
    ) {
    }

    /**
     * Destroy these messages, locally and at the provider.
     *
     * @param list<Message> $messages
     *
     * @return int how many rows were removed
     */
    public function purge(array $messages): int
    {
        if ([] === $messages) {
            return 0;
        }

        // Before anything is removed, and carrying the addresses rather than
        // the ids. LabelChangePropagator would have been the obvious route —
        // its delete() has existed unused since before this was written — but
        // every propagation message names local rows and lets the handler look
        // them up, and a purge is the one operation where those rows will not
        // be there when the worker runs. See PurgeRemoteMessagesMessage.
        $this->dispatchRemotePurge($messages);

        $threads = [];
        $purged  = 0;

        foreach ($messages as $message) {
            // Through the thread when the message has no account of its own,
            // which is the same two-association ownership path
            // MessageRepository::findOneForAccountByMessageId spells out.
            $account = $message->account ?? $message->thread?->account;

            if (null !== $message->thread) {
                $threads[spl_object_id($message->thread)] = $message->thread;
            }

            // Recorded while the id is still readable, and before the flush
            // that invalidates it. A JMAP client that never learns a message
            // was destroyed keeps showing it.
            if ($account instanceof Account && null !== $message->id) {
                $this->changes->emailDestroyed(
                    (int) $account->id,
                    (string) $message->id,
                    $message->thread,
                );
            }

            $this->removeFiles($message);

            // The parts explicitly: Message::$messageParts is a plain
            // OneToMany with neither cascade nor orphanRemoval, so removing the
            // message alone leaves rows pointing at one that no longer exists.
            // Doctrine notices before the database does — "a new entity was
            // found through the relationship" — which is a confusing way to be
            // told about a foreign key.
            //
            // Deliberately not fixed by adding cascade to the mapping. That
            // would change what happens on every other path that touches a
            // message, including the syncers that detach and re-attach parts,
            // and this is the only place that wants a part destroyed.
            foreach ($message->messageParts as $part) {
                $this->entityManager->remove($part);
            }

            $this->entityManager->remove($message);
            ++$purged;
        }

        $this->entityManager->flush();

        $this->reconcileThreads($threads);

        $this->entityManager->flush();

        return $purged;
    }

    /**
     * Queue the provider-side delete, one job per account.
     *
     * Grouped by account because an envelope authenticates as one account, and
     * split by the coordinate each provider understands: IMAP wants a folder
     * and a UID, Gmail its own message id, Graph its own. A message with none
     * of them — a draft that never reached a server, or one whose account has
     * gone — simply contributes nothing, and the job is not sent at all if the
     * whole batch is like that.
     *
     * @param list<Message> $messages
     */
    private function dispatchRemotePurge(array $messages): void
    {
        /** @var array<int, array{imap: array<string, list<int>>, gmail: list<string>, graph: list<string>}> $byAccount */
        $byAccount = [];

        foreach ($messages as $message) {
            $account = $message->account ?? $message->thread?->account;

            if (null === $account || null === $account->id) {
                continue;
            }

            $accountId             = (int) $account->id;
            $byAccount[$accountId] ??= ['imap' => [], 'gmail' => [], 'graph' => []];

            $folder = $message->mailbox?->fullPath;

            if (null !== $message->imapUid && null !== $folder) {
                $byAccount[$accountId]['imap'][$folder][] = $message->imapUid;
            }

            if (null !== $message->gmailId && '' !== $message->gmailId) {
                $byAccount[$accountId]['gmail'][] = $message->gmailId;
            }

            if (null !== $message->graphId && '' !== $message->graphId) {
                $byAccount[$accountId]['graph'][] = $message->graphId;
            }
        }

        foreach ($byAccount as $accountId => $coordinates) {
            $job = new PurgeRemoteMessagesMessage(
                $accountId,
                $coordinates['imap'],
                $coordinates['gmail'],
                $coordinates['graph'],
            );

            if (false === $job->isEmpty()) {
                $this->bus->dispatch($job);
            }
        }
    }

    /**
     * The bytes on disk, which the database rows do not take with them.
     *
     * Failures are logged and swallowed rather than aborting the delete. A file
     * that has already gone, or one on a volume that is momentarily unwritable,
     * must not leave the user with a message they asked to destroy still in
     * their mailbox — the row going is the part they can see, and a leftover
     * file is a cleanup problem rather than a correctness one.
     */
    private function removeFiles(Message $message): void
    {
        foreach ($message->messageParts as $part) {
            try {
                $this->attachments->delete($part->storagePath);
            } catch (\Throwable $e) {
                $this->logger->warning('MessagePurger: could not delete an attachment', [
                    'part'  => $part->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->raw->delete($message->rawPath);
        } catch (\Throwable $e) {
            $this->logger->warning('MessagePurger: could not delete a raw source', [
                'message' => $message->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * A conversation that has lost messages, and one that has lost all of them.
     *
     * messageCount is recounted from the association rather than decremented,
     * which is the rule DraftPersister and ComposeController::discard() already
     * state: the stored counter is what the thread header renders, and anything
     * that adjusts it by arithmetic drifts.
     *
     * An empty thread is removed outright. Leaving it would put a conversation
     * with no messages in the list — a row with a subject, no participants and
     * a count of zero, which nothing else in the app knows how to render.
     *
     * @param array<int, MessageThread> $threads
     */
    private function reconcileThreads(array $threads): void
    {
        foreach ($threads as $thread) {
            $remaining = $thread->messages->count();

            if (0 === $remaining) {
                $this->entityManager->remove($thread);

                continue;
            }

            $thread->messageCount = $remaining;
        }
    }
}
