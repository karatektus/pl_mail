<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\MailChangeRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * One message, one row — however many stores hold a copy of it.
 *
 * A message this installation sends exists in up to three places at once: the
 * row the composer wrote, the copy we APPEND into the IMAP Sent folder, and
 * whatever the provider files on its own account. Rows, though, were keyed on
 * where the server happened to put a copy — mailbox plus IMAP UID — and the
 * locally-composed row had no server location at all. So the Sent copy came
 * back on the next sync as an unrecognised UID, was inserted as a second row,
 * and the threader dutifully filed it next to the original: the reply you had
 * just written appeared twice in its own conversation, and the thread counted
 * one message more than it held.
 *
 * The identity a message actually keeps across all three stores is its RFC 5322
 * Message-ID, so that is what this reconciles on. MessageSendService mints one
 * before the mail leaves and writes it to the row and to the MIME, which is
 * what makes the copies recognisable at all — see its stampMessageId().
 *
 * Scope is the whole design here. Message-ID is matched within one account, and
 * never together with subject, body or sender as a fallback: two genuinely
 * distinct messages that happen to read alike keep their own rows, because they
 * keep their own ids. The one place this looks at content is repair() below,
 * and that is confined to rows the old send path left behind, which are
 * identifiable without content in the first place.
 */
final readonly class SentCopyReconciler
{
    public function __construct(
        private MessageRepository      $messages,
        private StateManager           $stateManager,
        private MailChangeRecorder     $changes,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    /**
     * The row that already stands for this Message-ID in this folder, or null
     * if the syncer should go ahead and build a new one.
     *
     * A returned row has been given the folder and UID the server just reported
     * where it did not have them, and needs nothing else: it has already been
     * through PostIngestPipeline once. Running it again would record a second
     * `created` for an id JMAP clients hold and re-apply the user's rules to
     * mail they may since have filed by hand — the same reasoning that keeps
     * the Gmailify claim out of the pipeline.
     */
    public function claim(Mailbox $mailbox, string $rfcMessageId, int $uid): ?Message
    {
        $rfcMessageId = MessageIdHelper::normalise($rfcMessageId);

        if ('' === $rfcMessageId) {
            return null;
        }

        $account = $mailbox->account;

        if (null === $account) {
            return null;
        }

        // Second server-side copy of a message already filed here — our APPEND
        // plus a provider that saves its own Sent copy of everything it
        // relays. Both are the same mail; the first one to arrive keeps the
        // row and the rest are dropped on the floor.
        //
        // Only in Sent, where "two copies in one folder" means that and only
        // that. Elsewhere a repeated Message-ID is somebody else's doing — a
        // list that resent, a server that redelivered — and folding those
        // together would be this class quietly deciding what counts as the
        // same incoming mail, which is not its job.
        if (MailboxSpecialUse::SENT === $mailbox->specialUse) {
            $sameFolder = $this->messages->findInMailboxByMessageId($mailbox, $rfcMessageId);

            if (null !== $sameFolder) {
                return $sameFolder;
            }
        }

        $claimable = $this->messages->findUnlocatedByMessageId($account, $rfcMessageId);

        if (null === $claimable) {
            return null;
        }

        $claimable->mailbox = $mailbox;
        $claimable->imapUid = $uid;

        $mailboxLabel = $mailbox->label;

        if (null !== $mailboxLabel) {
            $claimable->addLabel($mailboxLabel);
            $claimable->thread?->addLabel($mailboxLabel);

            // Nothing was created, but mailboxIds changed: EmailMapper reads
            // those off the labels, so a client that is following this id has
            // to be told the message now lives in a folder it did not before.
            $this->stateManager->recordUpdated(
                (int) $account->id,
                JmapObjectType::Email,
                (string) $claimable->id,
            );

            $claimedThread = $claimable->thread;

            if (null !== $claimedThread) {
                $this->stateManager->recordThreadsTouched(
                    (int) $account->id,
                    [(int) $claimedThread->id],
                );
            }
        }

        return $claimable;
    }

    /**
     * Self-repair for conversations that were duplicated before any of the
     * above existed.
     *
     * The old send path left a recognisable pair behind: a locally-written row
     * in the Sent folder with no UID and no Message-ID, and beside it the copy
     * the syncer imported from the server, which has both. claim() cannot undo
     * that — it runs on UIDs as they arrive, and these UIDs arrived months ago
     * — so this pairs them up on the next sync of the folder and takes the
     * duplicate out.
     *
     * The imported row is the one kept, and that is not arbitrary: it holds the
     * server UID, the real headers, the original bytes and the Message-ID, so
     * everything from here on — flag changes, moves, replies threading onto it
     * — works on it normally. The local row holds none of that and can only
     * ever be a ghost of it.
     *
     * The pairing is one-to-one. Two sends of the same subject in the same
     * conversation leave two ghosts and two imports; consuming each twin once
     * means two ghosts with one import removes one ghost, not both. A send that
     * never reached the server keeps its row, which is the whole reason to
     * count rather than to match loosely.
     *
     * @return int how many duplicate rows were removed
     */
    public function repair(Mailbox $mailbox): int
    {
        if (MailboxSpecialUse::SENT !== $mailbox->specialUse) {
            return 0;
        }

        $ghosts = $this->messages->findIdentitylessSentRows($mailbox);

        if (0 === count($ghosts)) {
            return 0;
        }

        /** @var array<int,true> $consumed */
        $consumed = [];
        $removed  = 0;

        foreach ($ghosts as $ghost) {
            $twin = null;

            foreach ($this->messages->findImportedTwinsOf($ghost) as $candidate) {
                if (true === isset($consumed[(int) $candidate->id])) {
                    continue;
                }

                $twin = $candidate;
                break;
            }

            if (null === $twin) {
                continue;
            }

            $consumed[(int) $twin->id] = true;

            $this->discard($ghost);
            ++$removed;
        }

        if ($removed > 0) {
            $this->em->flush();

            $this->logger->info('Removed duplicate Sent rows left by the pre-Message-ID send path', [
                'mailbox' => $mailbox->fullPath,
                'removed' => $removed,
            ]);
        }

        return $removed;
    }

    /**
     * Take one duplicate row out of its thread and out of the database.
     *
     * Recounted off the association rather than decremented, for the reason
     * ComposeController::discard() gives: the stored counters drift, and the
     * thread header showing "3 messages" over two is half of what the user
     * reported in the first place.
     *
     * Announced as a destroy before the flush, while the id still exists. It is
     * a genuine destroy — a client holding this id must drop it, not look for
     * it in Trash.
     */
    private function discard(Message $ghost): void
    {
        $thread    = $ghost->thread;
        $account   = $ghost->account;
        $ghostId   = $ghost->id;

        $this->em->remove($ghost);

        if (null !== $thread) {
            $thread->removeMessage($ghost);
            $thread->messageCount = $thread->messages->count();

            $unread = 0;
            $withAttachments = 0;

            foreach ($thread->messages as $remaining) {
                if (null === $remaining->seenAt) {
                    ++$unread;
                }

                if (true === $remaining->hasAttachments) {
                    ++$withAttachments;
                }
            }

            $thread->unreadCount     = $unread;
            $thread->attachmentCount = $withAttachments;
        }

        $this->changes->emailDestroyed((int) $account->id, (string) $ghostId, $thread);
    }
}
