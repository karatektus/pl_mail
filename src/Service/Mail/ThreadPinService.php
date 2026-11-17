<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Message;
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
 *
 * ## Two levels (#615)
 *
 * The list shows conversations or single emails, and a pin is kept where it
 * was made: on the conversation from the conversation list, on the email from
 * the email list. Each list reads both — a conversation holding a pinned
 * email is pinned, and a pinned conversation puts its newest received email
 * (anchorOf()) at the top of the email list — so switching views keeps every
 * pin. Taking a pin off takes off ALL of what pins that row, on either level:
 * a row that stays pinned after its thumbtack was clicked would be a bug to
 * the person clicking it.
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
     * Pin each email not pinned on its own already (#615) — the email list's
     * pin. Its conversation's pin, if it has one, is left alone: that is a pin
     * on the conversation list, and this one is only this email's.
     *
     * @param list<Message> $messages
     */
    public function pinEmails(array $messages, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();
        $changed = [];

        foreach ($messages as $message) {
            if (true === $message->isPinned($now)) {
                continue;
            }

            $days = $message->account->usr->pinDurationDays;

            $message->pinnedAt    = $now;
            $message->pinnedUntil = null === $days ? null : $now->modify(sprintf('+%d days', $days));
            $changed[]            = $message;
        }

        $this->recordEmails($changed);
        $this->em->flush();
    }

    /**
     * Take the pin off a conversation: its own, and every pin on an email in
     * it, because any of them keeps it at the top of the conversation list.
     * An ended pin's columns are cleared too: unpinning something the clock
     * already unpinned is a no-op to the reader, and it leaves nothing for
     * the sweep.
     *
     * @param list<MessageThread> $threads
     */
    public function unpin(array $threads): void
    {
        $changed = [];
        $emails  = [];

        foreach ($threads as $thread) {
            foreach ($thread->messages as $message) {
                if (null !== $message->pinnedAt) {
                    $message->pinnedAt    = null;
                    $message->pinnedUntil = null;
                    $emails[]             = $message;
                }
            }

            if (null === $thread->pinnedAt) {
                continue;
            }

            $thread->pinnedAt    = null;
            $thread->pinnedUntil = null;
            $changed[]           = $thread;
        }

        $this->record($changed);
        $this->recordEmails($emails);
        $this->em->flush();
    }

    /**
     * Take the pin off an email row: its own, and its conversation's, which is
     * what pins the row when it is that conversation's newest received email.
     * Other emails of the conversation keep their own pins; they are other
     * rows.
     *
     * @param list<Message> $messages
     */
    public function unpinEmails(array $messages): void
    {
        $emails  = [];
        $threads = [];

        foreach ($messages as $message) {
            if (null !== $message->pinnedAt) {
                $message->pinnedAt    = null;
                $message->pinnedUntil = null;
                $emails[]             = $message;
            }

            $thread = $message->thread;

            if (null !== $thread && null !== $thread->pinnedAt) {
                $thread->pinnedAt    = null;
                $thread->pinnedUntil = null;
                $threads[(int) $thread->id] = $thread;
            }
        }

        $this->record(array_values($threads));
        $this->recordEmails($emails);
        $this->em->flush();
    }

    /**
     * Whether the conversation list shows this conversation pinned: by its
     * own pin, or by a pin on any email in it.
     */
    public static function conversationPinned(MessageThread $thread, ?DateTimeImmutable $now = null): bool
    {
        if (true === $thread->isPinned($now)) {
            return true;
        }

        foreach ($thread->messages as $message) {
            if (true === $message->isPinned($now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the email list shows this email pinned: by its own pin, or as
     * the newest received email of a pinned conversation. The list's order
     * says the same in SQL — ListSortOrder::pinnedEmailFirst().
     */
    public static function emailPinned(Message $message, ?DateTimeImmutable $now = null): bool
    {
        if (true === $message->isPinned($now)) {
            return true;
        }

        $thread = $message->thread;

        return null !== $thread && true === $thread->isPinned($now) && $message === self::anchorOf($thread);
    }

    /**
     * The email a pinned conversation puts at the top of the email list: its
     * newest received one, which is what the person pinning was waiting on.
     * Received is not filed as Sent or Drafts (Message::isFiledAsSent()); a
     * conversation of nothing but one's own mail falls back to its newest.
     * Ties go to the higher id, as the list orders them.
     */
    public static function anchorOf(MessageThread $thread): ?Message
    {
        $newest   = null;
        $received = null;

        foreach ($thread->messages as $message) {
            if (true === self::newer($message, $newest)) {
                $newest = $message;
            }

            if (false === $message->isFiledAsSent() && true === self::newer($message, $received)) {
                $received = $message;
            }
        }

        return $received ?? $newest;
    }

    private static function newer(Message $message, ?Message $than): bool
    {
        if (null === $than) {
            return true;
        }

        $a = $message->receivedAt?->getTimestamp() ?? 0;
        $b = $than->receivedAt?->getTimestamp() ?? 0;

        return $a > $b || ($a === $b && (int) $message->id > (int) $than->id);
    }

    /**
     * Pin or unpin one conversation, decided by whether it is pinned now —
     * what a one-click button means.
     *
     * @return bool whether it is pinned afterwards
     */
    public function toggle(MessageThread $thread): bool
    {
        if (true === self::conversationPinned($thread)) {
            $this->unpin([$thread]);

            return false;
        }

        $this->pin([$thread]);

        return true;
    }

    /**
     * The same for an email row (#615).
     *
     * @return bool whether it is pinned afterwards
     */
    public function toggleEmail(Message $message): bool
    {
        if (true === self::emailPinned($message)) {
            $this->unpinEmails([$message]);

            return false;
        }

        $this->pinEmails([$message]);

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
     * Each moved email loses its own pin. Its conversation loses the
     * conversation's pin only when the whole of it moved: archiving one email
     * from the email list is not the conversation going anywhere (#615).
     *
     * Moves another mail client makes are not seen here and never take a pin
     * off: sync cannot reliably tell a move from a copy and a delete, and a pin
     * lost to a guess is worse than one kept.
     *
     * Records the change but does not flush; the move it belongs to does.
     *
     * @param list<Message> $messages
     */
    public function moved(array $messages, bool $discarded): void
    {
        $emails  = [];
        $threads = [];
        $moved   = [];

        foreach ($messages as $message) {
            if (false === $discarded && true === $message->account->usr->pinKeepOnMove) {
                continue;
            }

            if (null !== $message->pinnedAt) {
                $message->pinnedAt    = null;
                $message->pinnedUntil = null;
                $emails[]             = $message;
            }

            if (null !== $message->thread) {
                $moved[(int) $message->thread->id][(int) $message->id] = true;
                $threads[(int) $message->thread->id] = $message->thread;
            }
        }

        $changed = [];

        foreach ($threads as $id => $thread) {
            if (null === $thread->pinnedAt || count($moved[$id]) < $thread->messages->count()) {
                continue;
            }

            $thread->pinnedAt    = null;
            $thread->pinnedUntil = null;
            $changed[]           = $thread;
        }

        $this->record($changed);
        $this->recordEmails($emails);
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

        $emails = $this->pins->findEndedEmails($now, $limit);

        foreach ($emails as $message) {
            $message->pinnedAt    = null;
            $message->pinnedUntil = null;
        }

        $this->record($ended);
        $this->recordEmails($emails);
        $this->em->flush();

        return count($ended) + count($emails);
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

    /**
     * The same for pins on single emails: the email changed, and its thread,
     * whose pinned state may have.
     *
     * @param list<Message> $messages
     */
    private function recordEmails(array $messages): void
    {
        foreach ($messages as $message) {
            $accountId = (int) $message->account->id;

            $this->stateManager->recordUpdated($accountId, JmapObjectType::Email, (string) $message->id);

            if (null !== $message->thread) {
                $this->stateManager->recordThreadsTouched($accountId, [(int) $message->thread->id]);
            }
        }
    }
}
