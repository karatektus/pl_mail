<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Label\LabelRepository;
use App\Service\Label\LabelChangePropagator;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What starring, archiving, trashing, labelling and marking-read actually *do*
 * to a set of messages.
 *
 * Each of these is a label mutation first (the database is the source of
 * truth), then propagated to the provider asynchronously by
 * LabelChangePropagator: IMAP as flag/move operations, Gmail as
 * messages.batchModify. Archive is Archive added and Inbox removed; Trash is
 * Trash added and Inbox removed. For plain-IMAP messages the local mailbox
 * pointer is re-pointed optimistically so the sync layer stays coherent.
 *
 * Archive used to be the removal of Inbox alone, on the reasoning that this is
 * what Gmail does. Gmail can afford it because it has All Mail; plMail's
 * Archive screen is a role query, so removing Inbox without adding Archive put
 * the mail nowhere.
 *
 * Every method here ends in a flush, and every one records the change in the
 * JMAP log first. That ordering is the point of the class: a web-UI mutation
 * that skips the log is invisible to connected JMAP clients until something
 * else happens to touch the thread, and the two were previously only kept in
 * step by each controller action remembering to call both.
 *
 * Snoozing is the deliberate exception and lives in ThreadSnoozeService, which
 * has to move the Inbox label off and flush before it can queue its own
 * propagation.
 */
final readonly class ThreadStatusUpdater
{
    public function __construct(
        private EntityManagerInterface  $em,
        private LabelRepository         $labels,
        private LabelResolver           $labelResolver,
        private LabelChangePropagator   $propagator,
        private ThreadLabelSynchronizer $threadLabelSynchronizer,
        private StateManager            $stateManager,
    ) {
    }

    /**
     * Star or unstar, decided by the first message and applied to all of them.
     *
     * Only the first message's own star columns are written — the thread's star
     * is what the list renders, and a thread is starred as a whole.
     *
     * @param list<Message> $messages
     *
     * @return bool whether the set is starred now
     */
    public function star(array $messages): bool
    {
        $message = $messages[0];
        $starred = null === $message->starredAt;

        if (true === $starred) {
            $message->addFlag(MessageFlag::FLAGGED);
            $message->starredAt = new DateTimeImmutable();
            $message->thread->starredAt = new DateTimeImmutable();
        } else {
            $message->removeFlag(MessageFlag::FLAGGED);
            $message->starredAt = null;
            $message->thread->starredAt = null;
        }

        $this->propagator->star($messages, $starred);
        $this->recordJmapUpdates($messages);
        $this->em->flush();

        return $starred;
    }

    /**
     * @param list<Message> $messages
     */
    public function archive(array $messages): void
    {
        $account = $this->accountOf($messages[0]);

        $inboxLabel = $this->labels->findOneByRoleForUser(LabelRole::Inbox, $account->usr);

        // Propagate BEFORE re-pointing mailboxes so the IMAP job captures
        // the correct source folders.
        $this->propagator->archive($messages);

        $archiveLabel   = $this->labelResolver->systemLabel(LabelRole::Archive, $account);
        $archiveMailbox = $archiveLabel->bindingFor($account)?->mailbox;

        foreach ($messages as $message) {
            // Archiving used to be "remove Inbox" and nothing else, which read
            // as Gmail's rule and was not: Gmail keeps the mail in All Mail,
            // and plMail has no All Mail. What it has is an Archive VIEW, and
            // that view asks findForRole(Archive) — for a label nothing ever
            // attached. So archiving put mail in no list at all: gone from the
            // inbox, absent from Archive, its badge stuck at zero, reachable
            // only through search or through a custom label it happened to
            // carry. This is the line that was missing, and it is the same line
            // trash() has always had.
            $message->addLabel($archiveLabel);

            if (null !== $inboxLabel) {
                $message->removeLabel($inboxLabel);
            }

            // Plain-IMAP: the message physically moves to the Archive folder,
            // and gets a new UID there that only the mover can report. Until
            // then the row names the destination and no address inside it —
            // see Message::relocateTo().
            if (null !== $message->imapUid && null !== $archiveMailbox) {
                $message->relocateTo($archiveMailbox);
            }
        }

        $this->finish($messages);
    }

    /**
     * @param list<Message> $messages
     */
    public function trash(array $messages): void
    {
        $account = $this->accountOf($messages[0]);

        $inboxLabel = $this->labels->findOneByRoleForUser(LabelRole::Inbox, $account->usr);
        $trashLabel = $this->labelResolver->systemLabel(LabelRole::Trash, $account);

        $this->propagator->trash($messages);

        $trashMailbox = $trashLabel->bindingFor($account)?->mailbox;

        foreach ($messages as $message) {
            $message->addLabel($trashLabel);

            if (null !== $inboxLabel) {
                $message->removeLabel($inboxLabel);
            }

            if (null !== $message->imapUid && null !== $trashMailbox) {
                $message->relocateTo($trashMailbox);
            }
        }

        $this->finish($messages);
    }

    /**
     * Attach or detach a custom label across the set.
     *
     * @param list<Message> $messages
     */
    public function applyLabel(array $messages, Label $label, bool $attach): void
    {
        if (true === $attach) {
            foreach ($messages as $message) {
                $message->addLabel($label);
            }

            $this->propagator->attachLabel($messages, $label);
        } else {
            foreach ($messages as $message) {
                $message->removeLabel($label);
            }

            // Handles the IMAP location-label replacement (physical move)
            // internally; must run before flush.
            $this->propagator->detachLabel($messages, $label);
        }

        $this->finish($messages);
    }

    /**
     * @param list<Message> $messages
     */
    public function markRead(array $messages, bool $read): void
    {
        $unread = 0;

        foreach ($messages as $message) {
            if (true === $read) {
                $message->addFlag(MessageFlag::SEEN);
                $message->seenAt = new DateTimeImmutable();
            } else {
                $message->removeFlag(MessageFlag::SEEN);
                $message->seenAt = null;
                $unread++;
            }
        }

        $messages[0]->thread->unreadCount = $unread;

        $this->propagator->markRead($messages, $read);
        $this->recordJmapUpdates($messages);
        $this->em->flush();
    }

    /**
     * Which account a message belongs to.
     *
     * Gmail-API messages have no mailbox — the thread carries the account.
     */
    public function accountOf(Message $message): Account
    {
        $mailbox = $message->mailbox;

        if (null !== $mailbox) {
            return $mailbox->account;
        }

        return $message->thread->account;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The tail every label mutation shares: re-derive the thread's labels from
     * its messages, log the change, commit.
     *
     * @param list<Message> $messages
     */
    private function finish(array $messages): void
    {
        $this->threadLabelSynchronizer->sync($messages[0]->thread);
        $this->recordJmapUpdates($messages);
        $this->em->flush();
    }

    /**
     * Record a web-UI mutation in the JMAP change log so connected JMAP
     * clients see it on their next Email/changes. record() only persists, so
     * these rows commit on the caller's existing flush().
     *
     * @param list<Message> $messages
     */
    private function recordJmapUpdates(array $messages): void
    {
        $threadIdsByAccount = [];

        foreach ($messages as $message) {
            $accountId = (int) $message->account->id;

            $this->stateManager->recordUpdated(
                $accountId,
                JmapObjectType::Email,
                (string) $message->id,
            );

            $thread = $message->thread;

            if (null !== $thread) {
                $threadIdsByAccount[$accountId][] = (int) $thread->id;
            }
        }

        foreach ($threadIdsByAccount as $accountId => $threadIds) {
            $this->stateManager->recordThreadsTouched($accountId, $threadIds);
        }
    }
}
