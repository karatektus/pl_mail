<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\MessageThread\MessageThreadPinRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pinning a conversation to the top of its lists (#654), for every caller:
 * the row's button, the open conversation's toolbar, a swipe, the selection
 * toolbar, the JMAP $pinned keyword, and the moves that may take a pin off.
 *
 * One service so those cannot come to disagree about what a pin is. Writing
 * pinnedAt from a controller would skip the end time the owner set, and
 * writing it from Email/set would skip the change log the other clients
 * learn of it through.
 *
 * Nothing here talks to the mail server. A pin is plMail's own state: IMAP
 * has no standard keyword for it and Gmail and Outlook nothing at all.
 *
 * The reminder is asked, not enforced. Past the owner's count, the web asks
 * "pin anyway?" before calling pin(); this service pins whatever it is
 * handed, because the answer to the question is the person's, and JMAP
 * clients ask it themselves (urn:plmail:params:jmap:pins).
 */
final class ThreadPinService
{
    public function __construct(
        private readonly EntityManagerInterface     $em,
        private readonly MessageThreadPinRepository $pins,
        private readonly StateManager               $stateManager,
    ) {}

    /**
     * Whether pinning $adding more conversations would go past the owner's
     * reminder — the question the "pin anyway?" dialog is shown for.
     *
     * At the count, not above it: with the default of twenty, the twenty-first
     * pin is the one that asks.
     */
    public function wouldPassReminder(User $user, int $adding = 1): bool
    {
        $reminderAt = $user->pinReminderAt;

        if (0 === $reminderAt || $adding < 1) {
            return false;
        }

        return $this->pins->countActiveForUser($user) + $adding > $reminderAt;
    }

    public function activeCount(User $user): int
    {
        return $this->pins->countActiveForUser($user);
    }

    /**
     * Pin each conversation not pinned already, for as long as its owner's
     * "Pins last" says at this moment.
     *
     * A conversation already pinned keeps its pin as it is: pinning a selection
     * that includes one must not quietly restart that pin's clock.
     *
     * @param list<MessageThread> $threads
     */
    public function pin(array $threads, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();
        $changed = [];

        foreach ($threads as $thread) {
            if (true === $thread->isPinned($now)) {
                continue;
            }

            $days = $thread->account->usr->pinDurationDays;

            $thread->pinnedAt    = $now;
            $thread->pinnedUntil = null === $days ? null : $now->modify(sprintf('+%d days', $days));
            $changed[]           = $thread;
        }

        $this->record($changed);
        $this->em->flush();
    }

    /**
     * Take the pin off. An ended pin's columns are cleared too: unpinning
     * something the clock already unpinned is a no-op to the reader, and it
     * leaves nothing for the sweep.
     *
     * @param list<MessageThread> $threads
     */
    public function unpin(array $threads): void
    {
        $changed = [];

        foreach ($threads as $thread) {
            if (null === $thread->pinnedAt) {
                continue;
            }

            $thread->pinnedAt    = null;
            $thread->pinnedUntil = null;
            $changed[]           = $thread;
        }

        $this->record($changed);
        $this->em->flush();
    }

    /**
     * Pin or unpin one conversation, decided by whether it is pinned now —
     * what a one-click button means.
     *
     * @return bool whether it is pinned afterwards
     */
    public function toggle(MessageThread $thread): bool
    {
        if (true === $thread->isPinned()) {
            $this->unpin([$thread]);

            return false;
        }

        $this->pin([$thread]);

        return true;
    }

    /**
     * What a move does to a pin, called by ThreadStatusUpdater for every move
     * plMail makes — archive, Move to, back to the inbox, and the bin.
     *
     * Into the bin or spam always takes the pin off: those are where mail goes
     * to be gone, and a pin left on would come back with the mail if it were
     * restored. Anywhere else it is the owner's "Keep the pin when moved".
     *
     * Moves another mail client makes are not seen here and never take a pin
     * off: sync cannot reliably tell a move from a copy and a delete, and a pin
     * lost to a guess is worse than one kept.
     *
     * Records the change but does not flush; the move it belongs to does.
     *
     * @param list<MessageThread> $threads
     */
    public function moved(array $threads, bool $discarded): void
    {
        $changed = [];

        foreach ($threads as $thread) {
            if (null === $thread->pinnedAt) {
                continue;
            }

            if (false === $discarded && true === $thread->account->usr->pinKeepOnMove) {
                continue;
            }

            $thread->pinnedAt    = null;
            $thread->pinnedUntil = null;
            $changed[]           = $thread;
        }

        $this->record($changed);
    }

    /**
     * Clear pins whose end has passed. The lists already ignore them; this is
     * so JMAP clients, which hold the $pinned keyword, hear that it went.
     *
     * @return int how many were cleared
     */
    public function clearEnded(DateTimeImmutable $now, int $limit): int
    {
        $ended = $this->pins->findEnded($now, $limit);

        foreach ($ended as $thread) {
            $thread->pinnedAt    = null;
            $thread->pinnedUntil = null;
        }

        $this->record($ended);
        $this->em->flush();

        return count($ended);
    }

    /**
     * Tell JMAP clients: the $pinned keyword is on every email of the
     * conversation, so every one of them changed, and so did the thread.
     * Persisted only; the caller's flush commits it.
     *
     * @param list<MessageThread> $threads
     */
    private function record(array $threads): void
    {
        foreach ($threads as $thread) {
            $accountId = (int) $thread->account->id;

            foreach ($thread->messages as $message) {
                $this->stateManager->recordUpdated($accountId, JmapObjectType::Email, (string) $message->id);
            }

            $this->stateManager->recordThreadsTouched($accountId, [(int) $thread->id]);
        }
    }
}
