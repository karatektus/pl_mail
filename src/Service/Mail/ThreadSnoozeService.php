<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Label\Label\LabelRepository;
use App\Service\Label\LabelChangePropagator;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Snoozing a conversation, and bringing it back.
 *
 * Snooze is archive-with-a-timer: the thread leaves the Inbox now and returns
 * to it later. Expressed in labels like everything else — Inbox off, Snoozed
 * on — so the "a message carries at least one label" invariant holds without a
 * second mechanism, and so the snoozed pile is reachable by every path that
 * already works on labels: the sidebar, a query, the unified feed.
 *
 * The label change **propagates outward**, exactly as archiving does. That is
 * the point rather than a side effect: a snoozed conversation should be out of
 * the way in Gmail's inbox too, not just in plMail's view of it.
 *
 * One service rather than one implementation per caller. The JMAP method and
 * the web UI both go through here, because a snooze that meant something
 * different depending on which client did it is worse than no snooze.
 *
 * ## One email, or the conversation (#615)
 *
 * The list can show single emails, and a snooze made there is that email's:
 * the same labels on that email alone, with its own wake time on
 * Message::$snoozedUntil. The conversation stays in the inbox while it has
 * other emails there. A snooze made on the conversation keeps using
 * MessageThread::$snoozedUntil and covers every email of it.
 *
 * Unsnoozing takes off everything that put the email away: an email that is
 * snoozed because its conversation is wakes the whole conversation
 * (wakeEmails()), as the person asked for when the two views were designed.
 */
final class ThreadSnoozeService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LabelRepository $labelRepository,
        private readonly LabelResolver $labelResolver,
        private readonly ThreadLabelSynchronizer $threadLabelSynchronizer,
        private readonly LabelChangePropagator $propagator,
        private readonly StateManager $stateManager,
    ) {
    }

    /**
     * Puts a conversation away until `$until`.
     *
     * A time already in the past is not rejected: it simply means the next
     * sweep wakes it, which is a harmless way to express "bring this back
     * now" and saves callers a clock comparison.
     */
    public function snooze(MessageThread $thread, \DateTimeImmutable $until): void
    {
        $messages = $thread->messages->toArray();

        if ([] === $messages) {
            return;
        }

        $account = $messages[0]->account;
        $inbox = $this->labelRepository->findOneByRoleForUser(LabelRole::Inbox, $account->usr);
        $snoozed = $this->labelResolver->systemLabel(LabelRole::Snoozed, $account);

        // Before the labels move, so an IMAP job still sees the source folder.
        // Archiving is what snoozing does to the provider; the difference is
        // only that plMail remembers to undo it.
        $this->propagator->archive($messages);
        $this->propagator->attachLabel($messages, $snoozed);

        foreach ($messages as $message) {
            if (null !== $inbox) {
                $message->removeLabel($inbox);
            }

            $message->addLabel($snoozed);
        }

        $thread->snoozedUntil = $until;

        // The conversation's snooze covers every email of it now, so an
        // email snoozed on its own before is not woken separately later.
        foreach ($messages as $message) {
            $message->snoozedUntil = null;
        }

        $this->finish($thread, $messages);
    }

    /**
     * Puts single emails away until `$until` (#615): snooze() for the emails
     * given, not their whole conversation. Each keeps its own wake time.
     *
     * @param list<Message> $messages
     */
    public function snoozeEmails(array $messages, \DateTimeImmutable $until): void
    {
        if ([] === $messages) {
            return;
        }

        $account = $messages[0]->account;
        $inbox = $this->labelRepository->findOneByRoleForUser(LabelRole::Inbox, $account->usr);
        $snoozed = $this->labelResolver->systemLabel(LabelRole::Snoozed, $account);

        $this->propagator->archive($messages);
        $this->propagator->attachLabel($messages, $snoozed);

        foreach ($messages as $message) {
            if (null !== $inbox) {
                $message->removeLabel($inbox);
            }

            $message->addLabel($snoozed);
            $message->snoozedUntil = $until;
        }

        $this->finishEmails($messages);
    }

    /**
     * Brings single emails back (#615) — wake() for the emails given.
     *
     * An email that is away because its whole conversation was snoozed
     * brings the whole conversation back: unsnoozing takes off every snooze
     * that hid the row, not just the one made in this view.
     *
     * @param list<Message> $messages
     */
    public function wakeEmails(array $messages): void
    {
        $alone = [];

        foreach ($this->byThread($messages) as $group) {
            if (null !== $group[0]->thread && null !== $group[0]->thread->snoozedUntil) {
                $this->wake($group[0]->thread);

                continue;
            }

            array_push($alone, ...$group);
        }

        if ([] === $alone) {
            return;
        }

        $account = $alone[0]->account;
        $inbox = $this->labelResolver->systemLabel(LabelRole::Inbox, $account);
        $snoozed = $this->labelRepository->findOneByRoleForUser(LabelRole::Snoozed, $account->usr);
        $inboxMailbox = $inbox->bindingFor($account)?->mailbox;

        $this->propagator->restoreOnImap(array_values(array_filter(
            $alone,
            static fn ($message): bool => null !== $message->imapUid && null !== $inboxMailbox && $message->mailbox !== $inboxMailbox,
        )));

        foreach ($alone as $message) {
            $message->addLabel($inbox);

            if (null !== $snoozed) {
                $message->removeLabel($snoozed);
            }

            $message->removeFlag(MessageFlag::SEEN);
            $message->seenAt = null;
            $message->snoozedUntil = null;
        }

        if (null !== $snoozed) {
            $this->propagator->detachLabel($alone, $snoozed);
        }

        $this->propagator->attachLabel($alone, $inbox);
        $this->propagator->markRead($alone, false);

        $this->finishEmails($alone);
    }

    /**
     * Brings a conversation back to the Inbox and clears its snooze.
     *
     * Marks it unread, which is the whole point: a thread that returns in the
     * state you left it in is one you have already learned to scroll past. The
     * read state it had is genuinely lost — that is a deliberate trade, not an
     * oversight.
     *
     * Safe to call on a thread that is not snoozed; it then only ensures the
     * Inbox label, which is what a caller racing the sweep wants anyway.
     */
    public function wake(MessageThread $thread): void
    {
        $messages = $thread->messages->toArray();

        if ([] === $messages) {
            $thread->snoozedUntil = null;

            return;
        }

        $account = $messages[0]->account;
        $inbox = $this->labelResolver->systemLabel(LabelRole::Inbox, $account);
        $snoozed = $this->labelRepository->findOneByRoleForUser(
            LabelRole::Snoozed,
            $account->usr,
        );

        $inboxMailbox = $inbox->bindingFor($account)?->mailbox;
        $unread = 0;

        // Plain-IMAP: the message physically comes back to INBOX. Its copy
        // keeps the address it has until the server says where it landed
        // (#415). This used to re-point the row at INBOX and send nothing, so
        // the server kept the mail in Archive and the row claimed an INBOX
        // address it never got. The labels go through attachLabel() below.
        $this->propagator->restoreOnImap(array_values(array_filter(
            $messages,
            static fn ($message): bool => null !== $message->imapUid && null !== $inboxMailbox && $message->mailbox !== $inboxMailbox,
        )));

        foreach ($messages as $message) {
            $message->addLabel($inbox);

            if (null !== $snoozed) {
                $message->removeLabel($snoozed);
            }

            $message->removeFlag(MessageFlag::SEEN);
            $message->seenAt = null;
            ++$unread;
        }

        $thread->unreadCount = $unread;
        $thread->snoozedUntil = null;

        foreach ($messages as $message) {
            $message->snoozedUntil = null;
        }

        if (null !== $snoozed) {
            $this->propagator->detachLabel($messages, $snoozed);
        }

        $this->propagator->attachLabel($messages, $inbox);
        $this->propagator->markRead($messages, false);

        $this->finish($thread, $messages);
    }

    /**
     * finish() for emails that may span conversations: each conversation's
     * labels and unread count follow its emails, and JMAP hears of all of it.
     *
     * @param list<Message> $messages
     */
    private function finishEmails(array $messages): void
    {
        foreach ($this->byThread($messages) as $group) {
            $thread = $group[0]->thread;

            if (null === $thread) {
                foreach ($group as $message) {
                    $this->stateManager->recordUpdated((int) $message->account->id, JmapObjectType::Email, (string) $message->id);
                }

                continue;
            }

            $thread->unreadCount = MessageThread::unreadAmong($thread->messages);

            $this->threadLabelSynchronizer->sync($thread);

            foreach ($group as $message) {
                $this->stateManager->recordUpdated((int) $message->account->id, JmapObjectType::Email, (string) $message->id);
            }

            $this->stateManager->recordThreadsTouched((int) $group[0]->account->id, [(string) $thread->id]);
        }

        $this->entityManager->flush();
    }

    /**
     * @param list<Message> $messages
     *
     * @return array<int, non-empty-list<Message>> by thread id, 0 for none
     */
    private function byThread(array $messages): array
    {
        $groups = [];

        foreach ($messages as $message) {
            $groups[(int) $message->thread?->id][] = $message;
        }

        return $groups;
    }

    /**
     * @param list<\App\Entity\Mail\Message> $messages
     */
    private function finish(MessageThread $thread, array $messages): void
    {
        $this->threadLabelSynchronizer->sync($thread);

        foreach ($messages as $message) {
            $this->stateManager->recordUpdated(
                (int) $message->account->id,
                JmapObjectType::Email,
                (string) $message->id,
            );
        }

        $this->stateManager->recordThreadsTouched(
            (int) $messages[0]->account->id,
            [(string) $thread->id],
        );

        $this->entityManager->flush();
    }
}
