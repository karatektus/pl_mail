<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\MessageRepository;
use App\Service\Label\ThreadLabelSynchronizer;
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
 *
 * ## Moves, which are the same problem one folder further on
 *
 * The Sent fix named the defect — rows keyed on where a copy sits rather than
 * on what the message is — and then deliberately fixed it only for Sent, on the
 * grounds that a repeated Message-ID in INBOX is a list resending and folding
 * those is not this class's call. That reasoning still holds for a *repeat*.
 * It never covered a *move*, and a move is not a second message by anybody's
 * reading: the same mail, at a new address, because it left the old one.
 *
 * Trashing therefore duplicated every message it touched. The row was
 * re-pointed at Trash and kept the UID INBOX had issued it; the Trash sync met
 * the real Trash UID as mail it had never seen and inserted a second row; and
 * nothing ever removed the first, which now named an address the server had
 * disowned. Each further move added another ghost rather than replacing one,
 * which is how an account with 35 messages on the server came to hold 86 rows.
 *
 * Distinguishing a move from a copy is the whole difficulty, because plain IMAP
 * permits both and the destination looks identical either way. The evidence
 * that separates them is not in the destination at all — it is whether the
 * source copy is still there. A destination appearance *paired with* a source
 * disappearance is a move; a destination appearance while the source stays put
 * is a copy, and copies keep their own rows. So the source is checked against
 * the server, and no answer means no merge: this never guesses a move.
 */
final readonly class SentCopyReconciler
{
    /**
     * How many duplicated Message-IDs one sync of one folder will ask the
     * server about.
     *
     * A cap rather than a full pass because the accounts that need this most
     * are the ones carrying thousands of ghosts, and each one costs a round
     * trip. Draining a slice per sync repairs an account over an afternoon
     * without ever turning a routine poll into a probing run.
     */
    private const int REPAIR_BATCH = 100;

    public function __construct(
        private MessageRepository       $messages,
        private StateManager            $stateManager,
        private MailChangeRecorder      $changes,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
        private ThreadLabelSynchronizer $threadLabels,
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
     *
     * Three shapes of row can be claimed, in the order they are cheapest to be
     * certain about: a copy already filed in this very folder (Sent only), a
     * copy the account holds with no server address at all, and — needing the
     * server's help — a copy that until recently had an address in a different
     * folder. Only the last can be confused with a legitimate second copy,
     * which is what the probe is for.
     *
     * @param (callable(Mailbox, int): ?bool)|null $sourceStillExists  asks the
     *        server whether a UID is still in a folder. Null when there is no
     *        connection to ask with, in which case no move is ever inferred.
     */
    public function claim(
        Mailbox   $mailbox,
        string    $rfcMessageId,
        int       $uid,
        ?callable $sourceStillExists = null,
    ): ?Message {
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
            return $this->claimMoved($mailbox, $account, $rfcMessageId, $uid, $sourceStillExists);
        }

        $claimable->relocateTo($mailbox, $uid);

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
     * The row for a message that moved into this folder, or null to let the
     * syncer insert.
     *
     * Reached only when nothing unlocated matched, so the candidates here are
     * rows that do claim a server address — in another folder — under this
     * message's id. Each is either the same mail before it moved, or a second
     * copy that legitimately coexists.
     *
     * The probe is what decides, and its absence decides too: with no way to
     * ask the server, this returns null and a new row is inserted, which is
     * what the code did before any of this existed. Not merging a move costs a
     * duplicate that the repair pass will collect later; merging a copy
     * destroys a row the user can still see in the other folder. Only one of
     * those is recoverable.
     *
     * @param (callable(Mailbox, int): ?bool)|null $sourceStillExists  true, false,
     *        or null for "could not tell" — which is never read as "gone"
     */
    private function claimMoved(
        Mailbox   $mailbox,
        Account   $account,
        string    $rfcMessageId,
        int       $uid,
        ?callable $sourceStillExists,
    ): ?Message {
        if (null === $sourceStillExists) {
            return null;
        }

        $elsewhere = $this->messages->findLocatedByMessageIdElsewhere($account, $rfcMessageId, $mailbox);

        foreach ($elsewhere as $candidate) {
            $source = $candidate->mailbox;
            $sourceUid = $candidate->imapUid;

            if (null === $source || null === $sourceUid) {
                continue;
            }

            if (false !== $sourceStillExists($source, $sourceUid)) {
                // Still there, or unknowable. Either way this is a copy as far
                // as the evidence goes, and copies keep their own rows.
                continue;
            }

            $this->logger->info('Reconciled a moved message onto the row it already had', [
                'messageId'   => $rfcMessageId,
                'from'        => $source->fullPath,
                'to'          => $mailbox->fullPath,
                'uid'         => $uid,
            ]);

            $this->relocate($candidate, $source, $mailbox, $uid);

            return $candidate;
        }

        return null;
    }

    /**
     * Take one row from the folder it left to the folder it is in.
     *
     * The labels move with it, and that is the part that distinguishes this
     * from claim()'s other cases. A message the server moved out of INBOX is
     * not in INBOX any more, so keeping the Inbox label would leave it showing
     * in a list it has left — the same wrong answer as the duplicate, arrived
     * at from the other side. The destination's label goes on for the same
     * reason. Everything the user put there by hand stays untouched.
     */
    private function relocate(Message $message, Mailbox $from, Mailbox $to, int $uid): void
    {
        $message->relocateTo($to, $uid);

        $leaving = $from->label;

        if (null !== $leaving) {
            $message->removeLabel($leaving);
        }

        $arriving = $to->label;

        if (null !== $arriving) {
            $message->addLabel($arriving);
        }

        $thread = $message->thread;

        if (null !== $thread) {
            // The thread's labels are the union of its messages', so a message
            // changing folders can change them in both directions.
            $this->threadLabels->sync($thread);

            $this->stateManager->recordThreadsTouched(
                (int) $message->account->id,
                [(int) $thread->id],
            );
        }

        $this->stateManager->recordUpdated(
            (int) $message->account->id,
            JmapObjectType::Email,
            (string) $message->id,
        );
    }

    /**
     * Self-repair for the ghosts that moves have already left behind.
     *
     * claim() only ever sees UIDs as they arrive, and the duplicates on an
     * affected install arrived weeks ago, below every sync window — so they
     * need collecting rather than preventing, exactly as the pre-Message-ID
     * Sent pairs did in repair() above.
     *
     * The rule is one question asked of the server: of the rows this account
     * holds under one Message-ID, which ones are still where they say they are?
     * Exactly one still there and the rest disowned is a message that moved,
     * repeatedly, leaving a ghost at every stop — and the disowned rows go. Two
     * or more still there is a message that genuinely exists in two folders,
     * and nothing goes. None still there is a question the server has not
     * answered, and nothing goes then either: the last row of a message is
     * never removed on the strength of a failed probe.
     *
     * Ghost-per-move rather than ghost-per-message is why this counts survivors
     * instead of pairing twins. A mail dragged from INBOX to a spam folder to
     * Trash leaves three rows and only the last is real.
     *
     * @param (callable(Mailbox, int): ?bool)|null $stillExists
     *
     * @return int how many ghost rows were removed
     */
    public function repairRelocated(Mailbox $mailbox, ?callable $stillExists): int
    {
        if (null === $stillExists) {
            return 0;
        }

        $account = $mailbox->account;

        if (null === $account) {
            return 0;
        }

        $duplicated = $this->messages->findMessageIdsAlsoFiledElsewhere($mailbox, self::REPAIR_BATCH);

        if (0 === count($duplicated)) {
            return 0;
        }

        $removed = 0;

        foreach ($duplicated as $rfcMessageId) {
            $removed += $this->collapse($account, $rfcMessageId, $stillExists);
        }

        if ($removed > 0) {
            $this->em->flush();

            $this->logger->info('Removed rows left behind by moves', [
                'mailbox' => $mailbox->fullPath,
                'removed' => $removed,
            ]);
        }

        return $removed;
    }

    /**
     * @param callable(Mailbox, int): ?bool $stillExists
     */
    private function collapse(Account $account, string $rfcMessageId, callable $stillExists): int
    {
        $rows = $this->messages->findLocatedByMessageId($account, $rfcMessageId);

        if (count($rows) < 2) {
            return 0;
        }

        $ghosts   = [];
        $survivors = 0;

        foreach ($rows as $row) {
            $mailbox = $row->mailbox;
            $uid     = $row->imapUid;

            if (null === $mailbox || null === $uid) {
                continue;
            }

            $present = $stillExists($mailbox, $uid);

            if (true === $present) {
                ++$survivors;

                continue;
            }

            if (false === $present) {
                $ghosts[] = $row;
            }

            // null — the server did not say. Neither a survivor nor a ghost.
        }

        // Nothing to anchor the removal to. A message whose every copy has
        // vanished may simply have been deleted on the server, and deciding
        // that is not this pass's job; a probe that failed outright must never
        // read as deletion at all.
        if (0 === $survivors || 0 === count($ghosts)) {
            return 0;
        }

        // More than one real copy is a message that genuinely lives in two
        // folders. Its ghosts, if any, belong to whichever copy moved, and
        // pairing them up is guesswork this refuses to do.
        if ($survivors > 1) {
            return 0;
        }

        foreach ($ghosts as $ghost) {
            $this->discard($ghost);
        }

        return count($ghosts);
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
