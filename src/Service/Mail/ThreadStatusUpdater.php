<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\RemoteFlagState;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Infrastructure\Messaging\Message\SendReadReceiptMessage;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Label\LabelRepository;
use App\Service\Label\LabelChangePropagator;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

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
    /**
     * How long a local flag change is allowed to stay unconfirmed before the
     * provider's answer outranks it again.
     *
     * The guard has to expire, or it is the same bug in a smaller box: a job
     * lost for good — a transport wiped, a worker that never returns, an
     * account whose credentials stopped working — would freeze that row's flags
     * against the server permanently.
     *
     * An hour is generous on purpose. Messenger's retry ladder, a NAS asleep
     * until morning, a worker restarted mid-deploy: all minutes, none of them
     * an hour. A change still unconfirmed after one is not in flight, it is
     * lost, and the row should go back to agreeing with the server. The cost of
     * being wrong here is one reverted flag; the cost of no bound at all is a
     * row that never syncs its flags again.
     */
    public const int PENDING_GRACE_MINUTES = 60;

    public function __construct(
        private EntityManagerInterface  $em,
        private LabelRepository         $labels,
        private LabelResolver           $labelResolver,
        private LabelChangePropagator   $propagator,
        private ThreadLabelSynchronizer $threadLabelSynchronizer,
        private StateManager            $stateManager,
        private ReadReceiptPolicy       $readReceipts,
        private MessageBusInterface     $bus,
        private LoggerInterface         $logger,
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
     * Out of the trash or spam and back into the inbox.
     *
     * The counterpart the bin never had. Trash and Spam could be reached and
     * not left: an over-eager delete, or a real mail the filter got wrong,
     * could only be rescued by finding it and attaching Inbox by hand through
     * the label menu — which is not something a person looking at a wrongly
     * binned mail would think to do.
     *
     * All three roles are removed rather than only the one it was found under.
     * A message that carries Trash AND Spam — a spam mail somebody then deleted
     * — would otherwise come back into the inbox still marked as spam and
     * disappear again on the next rule run; and an archived conversation that
     * kept its Archive label would sit in the inbox and the archive at once.
     *
     * @param list<Message> $messages
     */
    public function restore(array $messages): void
    {
        $account = $this->accountOf($messages[0]);

        $inboxLabel = $this->labelResolver->systemLabel(LabelRole::Inbox, $account);
        $trashLabel   = $this->labels->findOneByRoleForUser(LabelRole::Trash, $account->usr);
        $spamLabel    = $this->labels->findOneByRoleForUser(LabelRole::Spam, $account->usr);
        $archiveLabel = $this->labels->findOneByRoleForUser(LabelRole::Archive, $account->usr);

        // Before the mailbox is re-pointed, so the IMAP job still knows which
        // folder to move the message out of — the same ordering archive() and
        // trash() rely on, and for the same reason.
        $this->propagator->restore($messages);

        $inboxMailbox = $inboxLabel->bindingFor($account)?->mailbox;

        foreach ($messages as $message) {
            $message->addLabel($inboxLabel);

            foreach ([$trashLabel, $spamLabel, $archiveLabel] as $label) {
                if (null !== $label) {
                    $message->removeLabel($label);
                }
            }

            if (null !== $message->imapUid && null !== $inboxMailbox) {
                $message->relocateTo($inboxMailbox);
            }
        }

        $this->finish($messages);
    }

    /**
     * Put a set of messages IN a folder, taking them out of the one they are
     * in now.
     *
     * The difference between this and applyLabel(attach: true) is the whole
     * reason it exists. Labelling is additive — a conversation gains "Receipts"
     * and stays in the inbox — and that is the right answer for a menu you tick
     * boxes in. Dragging a row onto a folder is not that gesture: nobody drags
     * something onto a folder and expects to find it in both places, and a drag
     * that quietly left the mail where it was would look like a failed drag.
     *
     * Which labels count as "where it is now" is the only judgement here, and
     * it is deliberately narrow: the four system locations, plus custom labels
     * that are bound to a real folder on this account. A plain tag survives the
     * move, because a tag is not a place — moving a conversation from Inbox to
     * Archive has never stripped "Receipts" off it and this must not either.
     *
     * The three system destinations delegate rather than reimplement. Each
     * carries provider semantics this method has no way to reproduce — Gmail
     * wants a real trash operation, not a label swap, and archive/restore each
     * have a documented history of getting the local half wrong. A fourth
     * arm for Spam would be a "mark as junk" action, which the interface does
     * not otherwise have; it goes through the generic path below, where
     * attaching Spam and detaching Inbox is resolved into a physical move by
     * LabelChangePropagator::resolveDestinationMailbox(), which prefers a
     * system Trash/Spam label over anything else the mail carries.
     *
     * @param list<Message> $messages
     */
    public function move(array $messages, Label $destination): void
    {
        $this->dropSnooze($messages);

        match ($destination->role) {
            LabelRole::Inbox   => $this->restore($messages),
            LabelRole::Archive => $this->archive($messages),
            LabelRole::Trash   => $this->trash($messages),
            default            => $this->moveToFolder($messages, $destination),
        };
    }

    /**
     * Put a conversation in an inbox category and record that a person chose
     * it.
     *
     * The pin is the point. Writing $thread->category alone would hold until
     * the next message in the conversation arrived and MessageThreader adopted
     * ITS derived category — most-recent-wins — at which point the tab strip
     * would quietly undo the move. See MessageThread::$categoryPinnedAt for
     * the two writers that read the flag.
     *
     * Local only, deliberately. Gmail has CATEGORY_* labels of its own and this
     * does not touch them, so a Gmail thread moved here reads as Primary in
     * plMail and stays where it was in Gmail's own web interface. Pushing it
     * would be a second, arguable feature — Gmail's categories are inbox tabs
     * for the Gmail UI, not a property of the mail — and getting it wrong would
     * write to somebody's real mailbox. What matters is that the local choice
     * cannot be overwritten by the sync, and the pin is what guarantees that.
     *
     * @return bool whether anything changed
     */
    public function setCategory(MessageThread $thread, MessageCategory $category): bool
    {
        if ($category === $thread->category && null !== $thread->categoryPinnedAt) {
            return false;
        }

        $thread->category         = $category;
        $thread->categoryPinnedAt = new DateTimeImmutable();

        // The thread's own row moved between two tabs; no message changed, so
        // there is nothing to tell the Email state about. recordThreadsTouched
        // is what JMAP clients watch for a Thread/changes — see
        // ThreadGetMethod, which already returns `category`.
        $this->stateManager->recordThreadsTouched(
            (int) $thread->account->id,
            [(int) $thread->id],
        );

        $this->em->flush();

        return true;
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

        // Collected before the loop writes seenAt, because "was unread until
        // just now" is the whole condition a receipt fires on and one line
        // later it is unrecoverable.
        $firstReads = [];

        foreach ($messages as $message) {
            if (true === $read) {
                if (null === $message->seenAt) {
                    $firstReads[] = $message;
                }

                $message->addFlag(MessageFlag::SEEN);
                $message->seenAt = new DateTimeImmutable();
            } else {
                $message->removeFlag(MessageFlag::SEEN);
                $message->seenAt = null;
                $unread++;
            }
        }

        // Per thread, counted from that thread's own messages.
        //
        // `$messages[0]->thread->unreadCount = $unread` was right while every
        // caller named one conversation: the total of the set WAS the total of
        // the thread. A bulk action passes several threads at once, and that
        // line then wrote the whole selection's count onto the first thread and
        // left the others untouched — so four conversations were marked read in
        // the database and three rows went on rendering as unread, because
        // `data-unread` reads the thread's counter rather than its messages.
        $counts = [];

        foreach ($messages as $message) {
            $thread = $message->thread;

            if (null === $thread) {
                continue;
            }

            $counts[spl_object_id($thread)] ??= ['thread' => $thread, 'unread' => 0];

            if (null === $message->seenAt) {
                ++$counts[spl_object_id($thread)]['unread'];
            }
        }

        foreach ($counts as ['thread' => $thread, 'unread' => $threadUnread]) {
            $thread->unreadCount = $threadUnread;
        }

        $this->propagator->markRead($messages, $read);
        $this->recordJmapUpdates($messages);
        $this->em->flush();

        // After the flush: the handler re-reads these rows from the database,
        // and with an in-memory or same-process transport it can run before
        // this method returns.
        $this->queueReadReceipts($firstReads);
    }

    /**
     * Queue an automatic read receipt for each message that just became read
     * for the first time.
     *
     * THIS METHOD IS THE ONE THAT MUST NOT BE MOVED. It hangs off markRead(),
     * which is the user-initiated path and nothing else: the thread view's
     * auto-mark on open, the row's mark-read button, the toolbar's bulk
     * action. Its inbound twin — applyRemoteFlags(), thirty lines down — takes
     * the provider's word for a flag and deliberately does not call this, so a
     * sync that discovers a message was already \Seen on another device sends
     * nothing. That distinction is the entire correctness question in read
     * receipts: a receipt claims a person displayed the message, and a sync
     * pass learning about a read that happened elsewhere, possibly weeks ago,
     * cannot make that claim. Two other writers of seenAt exist and are
     * likewise silent — the rule engine's markRead action, which is software
     * filing mail rather than a person reading it, and ThreadSnoozeService,
     * which only ever un-reads.
     *
     * Only genuine transitions, so re-marking an already-read message read —
     * which the bulk toolbar does routinely over a mixed selection — cannot
     * re-fire a receipt that has already gone.
     *
     * Never blocking and never fatal. The queue takes ids and the policy is
     * re-run in the handler, so an unreachable relay costs a receipt and not
     * the read; dispatch itself is guarded for the same reason, because a
     * transport that is down must not turn opening a message into an error.
     *
     * @param list<Message> $messages
     */
    private function queueReadReceipts(array $messages): void
    {
        foreach ($messages as $message) {
            if (false === $message->wantsReadReceipt()) {
                continue;
            }

            // Cheap pre-check so the common case — a mailbox on the default
            // "never" — does not queue a job per message read just to have the
            // handler decide against it. The handler re-runs the full policy
            // regardless; this is an optimisation, not the guard.
            if (false === $this->readReceipts->decide($message)->isAutomatic()) {
                continue;
            }

            $id = $message->id;

            if (null === $id) {
                continue;
            }

            try {
                $this->bus->dispatch(new SendReadReceiptMessage((int) $id));
            } catch (\Throwable $e) {
                $this->logger->error('ThreadStatusUpdater: could not queue read receipt', [
                    'messageId' => $id,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Take the provider's word for a set of messages' flags.
     *
     * The inbound twin of markRead() and star(), and deliberately in the same
     * class: what \Seen and \Flagged *mean* locally — a seenAt, a starredAt, a
     * thread star, a thread unread count, a row in the JMAP change log — is one
     * answer, and having the inbound direction reimplement it somewhere else is
     * how the two drift until a message is read in one client and unread in the
     * other's badge.
     *
     * The one thing it does not do is call the propagator, and that is the
     * whole difference. These changes came *from* the server, so sending them
     * back is at best a wasted round trip and at worst the echo that makes the
     * two directions argue.
     *
     * ## The echo guard lives here
     *
     * The user marks a message read. The row changes first, because the
     * database is the source of truth, and an outbound job is queued. Until
     * that job lands, the provider's honest answer is still the *old* one — and
     * believing it would revert the user, which the propagator would see as a
     * fresh local change and queue a job for, which the next pass would revert
     * again. A flap, from two directions each correctly reporting what it sees.
     *
     * Note what does not fix it: comparing timestamps. The local write happens
     * *before* the read — that is the shape of the race — so it is the older of
     * the two, and last-write-wins picks the server every time. What the local
     * change actually is, is unconfirmed, so that is what Message::$flagsTouchedAt
     * records: set where the outbound job is queued, cleared where the provider
     * accepts it. A row carrying one is left alone entirely, rather than merged,
     * because a message whose \Seen is in flight may have had its \Flagged
     * changed remotely meanwhile and a flag list cannot tell the two apart.
     *
     * The guard is checked here rather than in each provider's reconciler on
     * purpose: it is one rule, it applies to IMAP, Gmail and Graph alike, and a
     * guard that every caller has to remember is one a future caller will
     * forget.
     *
     * Counters are recounted from the thread's own messages rather than
     * adjusted, because a flag pass can change several messages of one thread
     * at once and in both directions; markRead()'s cheaper arithmetic works
     * because the user's action covers the whole selection.
     *
     * @param list<RemoteFlagState> $states
     * @param DateTimeImmutable|null $readAt  when the provider was asked. The
     *        echo guard is measured from here rather than from now, because a
     *        pass that took a minute to walk a large folder should judge its
     *        rows against the moment it asked, not the moment it got here.
     *
     * @return int how many rows actually changed
     */
    public function applyRemoteFlags(array $states, ?DateTimeImmutable $readAt = null): int
    {
        $readAt  = $readAt ?? new DateTimeImmutable();
        $cutoff  = $readAt->modify('-' . self::PENDING_GRACE_MINUTES . ' minutes');
        $changed = [];
        $threads = [];
        $expired = false;

        foreach ($states as $state) {
            $message = $state->message;

            $pending = $message->flagsTouchedAt;

            if (null !== $pending && $pending > $cutoff) {
                // A local flag change the provider has not confirmed. Its
                // answer is stale by construction — it predates the change —
                // and applying it would revert the user and start a flap. See
                // the method docblock.
                continue;
            }

            if (null !== $pending) {
                // The guard expired. Whatever was carrying that change is not
                // coming back, and leaving the mark would make this row skip
                // inbound flag sync for the rest of its life.
                $message->flagsTouchedAt = null;
                $expired                 = true;
            }

            $wasSeen    = null !== $message->seenAt;
            $wasFlagged = null !== $message->starredAt;

            $storedFlags = $state->storedFlags();

            // Both sides canonicalised, so that a mirror captured from a server
            // that writes `Seen` is not read as differing from a listing that
            // writes `\Seen`. Without it the first pass over a folder would
            // rewrite every row and log a JMAP change for each — see
            // MessageFlag::canonicalList().
            if (
                $wasSeen === $state->seen
                && $wasFlagged === $state->flagged
                && MessageFlag::canonicalList($message->flags) === $storedFlags
            ) {
                continue;
            }

            // The mirror first, so \Answered and \Draft — which the model
            // carries here and nowhere else — survive the refresh alongside the
            // two that have columns.
            $message->flags = $storedFlags;

            if ($wasSeen !== $state->seen) {
                // Now, not the server's idea of when: IMAP does not record when
                // a flag was set, so the only honest timestamp is the one at
                // which plMail learned of it. Null when unread, which is what
                // every unread query in the codebase asks about.
                $message->seenAt = true === $state->seen ? new DateTimeImmutable() : null;
            }

            if ($wasFlagged !== $state->flagged) {
                $message->starredAt = true === $state->flagged ? new DateTimeImmutable() : null;

                $thread = $message->thread;

                if (null !== $thread) {
                    // A thread is starred as a whole — the same rule star()
                    // works to, from the other direction.
                    $thread->starredAt = $message->starredAt;
                }
            }

            $changed[] = $message;

            $thread = $message->thread;

            if (null !== $thread) {
                $threads[(int) $thread->id] = $thread;
            }
        }

        if ([] === $changed) {
            if (true === $expired) {
                // Nothing to apply — the server agreed with the row all along —
                // but an expired guard mark was dropped and that has to reach
                // the database, or the next pass expires it again forever.
                $this->em->flush();
            }

            return 0;
        }

        foreach ($threads as $thread) {
            $unread = 0;

            foreach ($thread->messages as $message) {
                if (null === $message->seenAt) {
                    ++$unread;
                }
            }

            $thread->unreadCount = $unread;
        }

        $this->recordJmapUpdates($changed);
        $this->em->flush();

        return count($changed);
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
     * The generic half of move(): into a folder that is not one of the three
     * system destinations with methods of their own.
     *
     * ORDERING IS THE WHOLE OF THIS METHOD
     * ────────────────────────────────────
     * The destination is attached, and its arrival propagated, BEFORE anything
     * is detached. LabelChangePropagator::detachLabel() decides where a plain
     * IMAP message physically goes by looking at the labels the message still
     * carries after the detach — so detaching first would ask it to choose a
     * destination from a set the destination is not in yet, and the mail would
     * be moved to whatever else happened to be attached, or archived as a
     * fallback when nothing was. Attach first and the answer is the folder the
     * person dropped it on.
     *
     * The detaches then run one label at a time rather than as a set, because
     * that is the only granularity the propagator offers and each one has to be
     * followed by its own provider dispatch. In the overwhelmingly common case
     * there is exactly one: mail lives in one place.
     *
     * @param list<Message> $messages
     */
    private function moveToFolder(array $messages, Label $destination): void
    {
        $sources = [];

        foreach ($messages as $message) {
            foreach ($this->locationLabelsOf($message) as $label) {
                if ($label === $destination) {
                    // Already here. Detaching and re-attaching the same label
                    // would be a physical move to the folder the mail is in,
                    // which some servers answer by giving it a new UID and
                    // others by refusing outright.
                    continue;
                }

                $sources[spl_object_id($label)] ??= ['label' => $label, 'messages' => []];
                $sources[spl_object_id($label)]['messages'][] = $message;
            }

            $message->addLabel($destination);
        }

        $this->propagator->attachLabel($messages, $destination);

        foreach ($sources as $source) {
            foreach ($source['messages'] as $message) {
                $message->removeLabel($source['label']);
            }

            // Handles the IMAP location-label replacement (physical move)
            // internally; must run before flush.
            $this->propagator->detachLabel($source['messages'], $source['label']);
        }

        $this->finish($messages);
    }

    /**
     * The labels on a message that say WHERE IT IS, as opposed to what it is
     * about.
     *
     * A system label with a folder behind it is a location by definition —
     * those are the app's own folders, and LabelRole::hasProviderFolder() is
     * the existing spelling of "the server has one of these too". A custom
     * label is one only when it is bound to a real folder on this account,
     * which is what LabelBinding::$mailbox records: a label that exists as an
     * IMAP folder is somewhere mail can be, and a label that is only a row in
     * our database is a tag the mail wears wherever it lives.
     *
     * Getting this wrong in the generous direction — treating every label as a
     * location — would make a move strip every tag off the conversation, and
     * the tags are the part nobody could get back.
     *
     * Snoozed falls out of this for free, and must: it is the one role with no
     * folder anywhere, so detaching it as though it were a source location
     * would ask the propagator to move mail out of a place that does not exist.
     * The snooze itself is dropped in move(), where the column can be cleared
     * alongside it.
     *
     * @return list<Label>
     */
    private function locationLabelsOf(Message $message): array
    {
        $account   = $this->accountOf($message);
        $locations = [];

        foreach ($message->labels as $label) {
            $role = $label->role;

            if (null !== $role) {
                if (true === $role->hasProviderFolder()) {
                    $locations[] = $label;
                }

                continue;
            }

            if (null !== $label->bindingFor($account)?->mailbox) {
                $locations[] = $label;
            }
        }

        return $locations;
    }

    /**
     * Filing a conversation ends the wait it was put on.
     *
     * A snoozed thread is out of the inbox with a time on it, and the sweep
     * puts it back when that time comes. Moving one somewhere without saying
     * anything about the snooze produces the worst kind of failure this feature
     * could have: the drag works, the row lands in the folder, and then hours
     * later the sweep pulls the whole conversation back into the inbox and
     * marks it unread — an action with no visible cause, arriving long after
     * the one that provoked it.
     *
     * So the move supersedes the snooze. Not through ThreadSnoozeService::wake(),
     * which is a different statement: waking means "the wait is over, here it is
     * again", and it says so by putting the thread back in the inbox and marking
     * every message unread. Neither is wanted by somebody who has just decided
     * where this conversation goes.
     *
     * Only move() calls this. archive()/trash()/restore() have the same latent
     * problem when reached from their own buttons, and that is a separate
     * report with its own history; fixing it here would change what three
     * long-standing actions do on the way past.
     *
     * @param list<Message> $messages
     */
    private function dropSnooze(array $messages): void
    {
        // Which threads were snoozed is read BEFORE anything is cleared, and
        // that ordering is load-bearing: a thread's messages arrive here one
        // after another, so clearing snoozedUntil on the first one would make
        // the second look un-snoozed and leave it holding the Snoozed label
        // the first one shed. Half a thread in a place that no longer exists.
        $snoozed = [];

        foreach ($messages as $message) {
            $thread = $message->thread;

            if (null !== $thread && null !== $thread->snoozedUntil) {
                $snoozed[spl_object_id($thread)] = $thread;
            }
        }

        if ([] === $snoozed) {
            return;
        }

        $snoozedLabel = $this->labels->findOneByRoleForUser(
            LabelRole::Snoozed,
            $this->accountOf($messages[0])->usr,
        );

        foreach ($snoozed as $thread) {
            $thread->snoozedUntil = null;
        }

        if (null === $snoozedLabel) {
            return;
        }

        foreach ($messages as $message) {
            $thread = $message->thread;

            if (null !== $thread && true === isset($snoozed[spl_object_id($thread)])) {
                $message->removeLabel($snoozedLabel);
            }
        }
    }

    /**
     * The tail every label mutation shares: re-derive the thread's labels from
     * its messages, log the change, commit.
     *
     * @param list<Message> $messages
     */
    private function finish(array $messages): void
    {
        // Every distinct thread, not just the first one's.
        //
        // Each of these methods used to be reached from a route naming ONE
        // conversation, so `$messages[0]->thread` was the whole set and saying
        // so was honest. A bulk action passes messages from many threads at
        // once, and a thread whose labels are not resynced keeps the Inbox
        // label its messages no longer have — so the list goes on showing a
        // conversation that has been archived. It answers 200, reports the
        // right count, and changes nothing on screen, which is the most
        // expensive kind of correct.
        $threads = [];

        foreach ($messages as $message) {
            $thread = $message->thread;

            if (null !== $thread) {
                $threads[spl_object_id($thread)] = $thread;
            }
        }

        foreach ($threads as $thread) {
            $this->threadLabelSynchronizer->sync($thread);
        }

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
