<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Message;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\MessageRepository;
use App\Service\Label\ThreadLabelSynchronizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The link between a Gmailify sibling's own IMAP copy of a message and the
 * Gmail mailbox that collected the same mail: making it, undoing it, and
 * putting right what it got wrong.
 *
 * SyncGmailMessageBatchHandler makes the link. When a Gmail mailbox fetches
 * mail for another connected address and that account's IMAP sync already
 * holds it, the IMAP row is given the Gmail id, the carrier and Gmail's
 * labels, so a change made here reaches both servers. The row stays the IMAP
 * server's: its folder says where it is, and IMAP alone may delete it.
 *
 * ── Which rows these are ─────────────────────────────────────────────────────
 * A carried row WITH an IMAP folder. A carried row without one was built from
 * the Gmail copy for a sibling whose own sync did not have it; Gmail is that
 * row's only source, it is left to Gmail in every case here, and the history
 * feed's deletions still erase it.
 */
final readonly class GmailifyLinks
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private MessageRepository       $messages,
        private GmailLabelPolicy        $labelPolicy,
        private ThreadLabelSynchronizer $threadLabels,
        private StateManager            $stateManager,
        private EntityManagerInterface  $em,
    ) {}

    /** Whether a row is a sibling's own IMAP copy that a Gmail mailbox carries. */
    public static function isImapCopy(Message $row): bool
    {
        return null !== $row->gmailCarrierAccount && null !== $row->mailbox;
    }

    /**
     * Take the Gmail identity off a sibling's IMAP copy, and leave the copy.
     *
     * The Gmail id, the carrier and the label ids go, and so do the labels
     * Gmail put on: the ones bound on the carrier, which is how
     * GmailLabelPolicy tells Gmail's from plMail's own. The folder's label and
     * the placement labels stay, whatever Gmail said about them: those are the
     * IMAP folder's to decide, and placeByFolder() is what corrects them.
     *
     * Not flushed; the caller flushes. Recorded for JMAP, since the labels are
     * the message's mailboxIds.
     */
    public function detach(Message $row): void
    {
        $carrier = $row->gmailCarrierAccount;

        if (null === $carrier) {
            return;
        }

        $folder = $row->mailbox?->label;

        foreach ($this->labelPolicy->providerLabels($row, $carrier) as $label) {
            if ($label === $folder || true === self::isPlacement($label->role)) {
                continue;
            }

            $row->removeLabel($label);
        }

        $row->gmailId             = null;
        $row->gmailCarrierAccount = null;
        $row->gmailLabelIds       = null;

        $this->touched($row);
    }

    /**
     * Detach every IMAP copy this Gmail mailbox carries. For the Gmailify
     * switch being turned off: from then on the mailbox keeps only mail
     * addressed to itself, and a sibling's mail it went on carrying would keep
     * changing on Gmail's word.
     *
     * Rows Gmail built for a sibling are left linked; see the class comment.
     *
     * Run from SeverGmailifyLinksHandler, never inside a request: a mailbox
     * that collected an address's mail for years carries tens of thousands of
     * copies. Flushed and cleared per page so the identity map never holds
     * them all, which is why it takes the carrier's id and not the entity.
     *
     * @return int the rows detached
     */
    public function severCarriedBy(int $carrierId): int
    {
        $detached = 0;
        $afterId  = 0;

        while (true) {
            $rows = $this->messages->findImapCopiesCarried($carrierId, $afterId, self::BATCH_SIZE);

            if (0 === count($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $afterId = (int) $row->id;
                $this->detach($row);
                ++$detached;
            }

            $this->em->flush();
            $this->em->clear();
        }

        return $detached;
    }

    /**
     * Make a carried IMAP copy's placement agree with its folder again.
     *
     * Before #203 the Gmail copy's placement labels were merged onto the IMAP
     * copy: mail trashed in Gmail showed as trashed here while the IMAP server
     * still had it in its Inbox, and mail Gmail had archived came out of the
     * Inbox and into Archive. The merge no longer does that; this repairs the
     * rows it already did it to.
     *
     * Inbox, Sent, Drafts, Trash and Spam that are not the folder's label come
     * off, and the folder's label goes on if it is missing. Archive comes off
     * a message whose folder is the Inbox, which cannot be archived and in its
     * Inbox at once; elsewhere Archive is left alone, since an IMAP account
     * without an archive folder may archive by label. A folder with no label
     * is left as it is: there is nothing to say where it puts the message.
     *
     * Not flushed; the caller flushes.
     *
     * @return bool whether anything changed
     */
    public function placeByFolder(Message $row): bool
    {
        $folder = $row->mailbox?->label;

        if (null === $folder) {
            return false;
        }

        $changed = false;

        foreach ($row->labels->toArray() as $label) {
            if ($label === $folder) {
                continue;
            }

            $stray = true === self::isPlacement($label->role)
                || (LabelRole::Archive === $label->role && LabelRole::Inbox === $folder->role);

            if (true === $stray) {
                $row->removeLabel($label);
                $changed = true;
            }
        }

        if (false === $row->hasLabel($folder)) {
            $row->addLabel($folder);
            $changed = true;
        }

        if (true === $changed) {
            $this->touched($row);
        }

        return $changed;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function isPlacement(?LabelRole $role): bool
    {
        return null !== $role && true === in_array($role, GmailMessageBuilder::PLACEMENT_ROLES, true);
    }

    /**
     * The thread's labels are the union of its messages', and the inbox list
     * and the sidebar counts read the thread; a JMAP client reads mailboxIds
     * off the message and Thread state off the thread.
     */
    private function touched(Message $row): void
    {
        $accountId = (int) $row->account->id;

        $this->stateManager->recordUpdated($accountId, JmapObjectType::Email, (string) $row->id);

        $thread = $row->thread;

        if (null !== $thread) {
            $this->threadLabels->sync($thread);
            $this->stateManager->recordThreadsTouched($accountId, [(int) $thread->id]);
        }
    }
}
