<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Mail\MessageThread;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Label\LabelRepository;
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
        $messages = $thread->getMessages()->toArray();

        if ([] === $messages) {
            return;
        }

        $account = $messages[0]->getAccount();
        $inbox = $this->labelRepository->findOneByRoleForUser(LabelRole::Inbox, $account->getUsr());
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

        $thread->setSnoozedUntil($until);

        $this->finish($thread, $messages);
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
        $messages = $thread->getMessages()->toArray();

        if ([] === $messages) {
            $thread->setSnoozedUntil(null);

            return;
        }

        $account = $messages[0]->getAccount();
        $inbox = $this->labelResolver->systemLabel(LabelRole::Inbox, $account);
        $snoozed = $this->labelRepository->findOneByRoleForUser(
            LabelRole::Snoozed,
            $account->getUsr(),
        );

        $inboxMailbox = $inbox->bindingFor($account)?->mailbox;
        $unread = 0;

        foreach ($messages as $message) {
            $message->addLabel($inbox);

            if (null !== $snoozed) {
                $message->removeLabel($snoozed);
            }

            // Plain-IMAP: the message physically comes back to INBOX.
            if (null !== $message->getImapUid() && null !== $inboxMailbox) {
                $message->setMailbox($inboxMailbox);
            }

            $message->removeFlag(MessageFlag::SEEN)->setSeenAt(null);
            ++$unread;
        }

        $thread->setUnreadCount($unread);
        $thread->setSnoozedUntil(null);

        if (null !== $snoozed) {
            $this->propagator->detachLabel($messages, $snoozed);
        }

        $this->propagator->attachLabel($messages, $inbox);
        $this->propagator->markRead($messages, false);

        $this->finish($thread, $messages);
    }

    /**
     * @param list<\App\Entity\Mail\Message> $messages
     */
    private function finish(MessageThread $thread, array $messages): void
    {
        $this->threadLabelSynchronizer->sync($thread);

        foreach ($messages as $message) {
            $this->stateManager->recordUpdated(
                (int) $message->getAccount()->getId(),
                JmapObjectType::Email,
                (string) $message->getId(),
            );
        }

        $this->stateManager->recordThreadsTouched(
            (int) $messages[0]->getAccount()->getId(),
            [(string) $thread->getId()],
        );

        $this->entityManager->flush();
    }
}
