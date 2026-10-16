<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Repository\Insight\MailInsightRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\MailChangeRecorder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Folding one conversation into another, when threading learns late that they
 * are the same one.
 *
 * MessageThreader is the only caller. A message that arrives after the replies
 * to it — the replies in INBOX, the message they answer in Sent Items, synced
 * one folder after the other (#172) — finds those replies already sitting in
 * threads of their own, and the two have to become one. A rebuild from scratch
 * (`app:backfill rethread`) gets the same answer, and is what people had to run
 * after every large import.
 *
 * ONE THREAD SURVIVES, AND IT IS THE ONE CLIENTS ALREADY KNOW
 * ───────────────────────────────────────────────────────────
 * The thread with the lower id is kept; one the database has not issued an id
 * to yet counts as the newest. In the case this exists for, the late message
 * has just opened a thread nobody has seen, and its replies' thread has been in
 * the list for minutes or days: keeping that one means a JMAP client's id, an
 * AI summary and an insight card's link all keep pointing at something. The
 * other order would destroy the conversation people know and keep the one
 * that exists only in this unit of work.
 *
 * A thread with a provider conversation id is never the one absorbed, and two
 * of them are never merged. The key is what step 1 of the threader finds a
 * Gmail or Graph conversation by; a thread that lost it would be created again
 * by the next message, and two different keys are the provider saying in so
 * many words that these are different conversations.
 *
 * WHAT THE SURVIVOR TAKES FROM THE OTHER
 * ──────────────────────────────────────
 * The rules are the ones the threader applies to a single message joining, so a
 * merged conversation looks the way it would have if the mail had arrived in
 * order: counters add up, labels are the union (a thread's labels are its
 * messages' labels), lastMessageAt and the category follow the newest mail, a
 * chosen category wins over a derived one, and unread mail coming in makes the
 * conversation new again. Starring and snoozing carry over when the survivor
 * has none of its own — a snooze in particular, because the absorbed messages
 * carry the Snoozed label and nothing else would ever wake them. The subject is
 * the upstream side's: it holds the message the others answer, so it is the
 * earlier part of the conversation, which is what a thread is named after.
 *
 * A stored AI summary of the absorbed thread goes with it (ON DELETE CASCADE).
 * The survivor's own summary is kept and shows as stale, because its transcript
 * has grown; carrying the other one over would only replace one stale summary
 * with another. The rethread backfill makes the same choice.
 *
 * SAFE INSIDE A BATCH
 * ───────────────────
 * Nothing here flushes. A sync batch threads every message and flushes once at
 * the end, so a reply threaded moments ago has its thread only in memory, and a
 * thread created moments ago has no id. Messages are therefore moved by walking
 * the unit of work, after loading the absorbed thread's stored rows into it, and
 * never by an UPDATE: a statement would miss everything not yet flushed, and
 * the flush would then write the stale thread back. The thread row is removed
 * through the EntityManager, which deletes it after the messages' updates —
 * message.thread_id cascades on delete, so the order is what keeps the mail.
 */
final readonly class ThreadMerger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageRepository      $messageRepository,
        private MailInsightRepository  $insightRepository,
        private MailChangeRecorder     $changes,
    ) {}

    /**
     * Make one conversation of the two.
     *
     * @param MessageThread $upstream   the thread of the message being threaded
     * @param MessageThread $downstream the thread of a message that answers it
     *
     * @return MessageThread the thread both now share — either of the two, or
     *                       $upstream unchanged when they must stay apart
     */
    public function merge(MessageThread $upstream, MessageThread $downstream): MessageThread
    {
        if ($upstream === $downstream) {
            return $upstream;
        }

        if (null !== $upstream->providerThreadKey && null !== $downstream->providerThreadKey) {
            return $upstream;
        }

        $survivor = $this->survivorOf($upstream, $downstream);
        $absorbed = $survivor === $upstream ? $downstream : $upstream;

        // A thread that has no id cannot be pointed at by a statement, and the
        // rows of one that has are only found by the id. The one combination
        // that would need both — a stored thread folded into one that is not
        // stored yet — cannot happen under survivorOf() except through a
        // provider key, and is left alone rather than half done.
        if (null !== $absorbed->id && null === $survivor->id) {
            return $upstream;
        }

        $this->carryState($absorbed, $survivor, $upstream);

        $moved = $this->moveMessages($absorbed, $survivor);

        $this->announce($absorbed, $survivor, $moved);

        $this->entityManager->remove($absorbed);

        return $survivor;
    }

    /**
     * Lower id first, unflushed last, and a provider-keyed thread always.
     *
     * Between two threads neither of which has an id, the upstream one is kept:
     * both exist only in this batch, and that one holds the earlier mail.
     */
    private function survivorOf(MessageThread $upstream, MessageThread $downstream): MessageThread
    {
        if (null !== $upstream->providerThreadKey) {
            return $upstream;
        }

        if (null !== $downstream->providerThreadKey) {
            return $downstream;
        }

        $upstreamRank   = $upstream->id ?? PHP_INT_MAX;
        $downstreamRank = $downstream->id ?? PHP_INT_MAX;

        return $downstreamRank < $upstreamRank ? $downstream : $upstream;
    }

    private function carryState(MessageThread $absorbed, MessageThread $survivor, MessageThread $upstream): void
    {
        if ($survivor !== $upstream) {
            $survivor->subject           = $upstream->subject;
            $survivor->normalizedSubject = $upstream->normalizedSubject;
        }

        $survivor->messageCount    = (int) $survivor->messageCount + (int) $absorbed->messageCount;
        $survivor->unreadCount     = (int) $survivor->unreadCount + (int) $absorbed->unreadCount;
        $survivor->attachmentCount = $survivor->attachmentCount + $absorbed->attachmentCount;

        foreach ($absorbed->labels as $label) {
            $survivor->addLabel($label);
        }

        $survivor->starredAt    ??= $absorbed->starredAt;
        $survivor->snoozedUntil ??= $absorbed->snoozedUntil;

        // MessageThreader::attachMessageToThread()'s rule for one message,
        // applied to the batch of them: unread mail joining makes the
        // conversation new again, and mail that was read already does not —
        // which is what the late parent usually is, a copy from Sent Items.
        if ((int) $absorbed->unreadCount > 0 && null === $absorbed->listedAt) {
            $survivor->listedAt = null;
        }

        $this->carryCategory($absorbed, $survivor);

        $absorbedLast = $absorbed->lastMessageAt;

        if (null !== $absorbedLast && (null === $survivor->lastMessageAt || $absorbedLast > $survivor->lastMessageAt)) {
            $survivor->lastMessageAt = $absorbedLast;
        }
    }

    /**
     * A chosen category beats a derived one; between two derived ones, the
     * newer mail decides — MessageThreader::adoptCategory()'s two rules.
     *
     * Read before lastMessageAt moves, since "newer" is a comparison with it.
     * A thread whose category is null is being held for the assistant and has
     * no opinion yet, so it never overrides one that does.
     */
    private function carryCategory(MessageThread $absorbed, MessageThread $survivor): void
    {
        if (null !== $survivor->categoryPinnedAt) {
            return;
        }

        $absorbedLast = $absorbed->lastMessageAt;
        $isNewer      = null !== $absorbedLast
            && (null === $survivor->lastMessageAt || $absorbedLast > $survivor->lastMessageAt);

        if (null === $absorbed->categoryPinnedAt && (false === $isNewer || null === $absorbed->category)) {
            return;
        }

        $survivor->category         = $absorbed->category;
        $survivor->userCategory     = $absorbed->userCategory;
        $survivor->categoryPinnedAt = $absorbed->categoryPinnedAt;
    }

    /**
     * Every message the absorbed thread holds, in memory or in the database,
     * now in the survivor.
     *
     * The query is there for its side effect: it puts the stored rows into the
     * unit of work, where the scan finds them beside the ones threaded earlier
     * in this batch. Rows the unit of work already held come back as those
     * instances, with their in-memory thread, so a message that has since moved
     * elsewhere is not dragged back. New rows are not in the identity map until
     * they are flushed, hence the second list.
     *
     * @return list<Message>
     */
    private function moveMessages(MessageThread $absorbed, MessageThread $survivor): array
    {
        if (null !== $absorbed->id) {
            $this->messageRepository->findBy(['thread' => $absorbed]);
        }

        $unitOfWork = $this->entityManager->getUnitOfWork();
        $candidates = [
            ...array_values($unitOfWork->getIdentityMap()[Message::class] ?? []),
            ...array_values($unitOfWork->getScheduledEntityInsertions()),
        ];

        $moved = [];

        // Through addMessage(), not only the owning side: the batch is not over.
        // The rules run after threading and before the flush, and a label
        // action re-derives the thread's labels from $survivor->messages
        // (ThreadLabelSynchronizer). Loaded from the database at that point,
        // the collection still lists only the survivor's own mail, and the
        // labels of everything moved in were taken off the conversation again.
        foreach ($candidates as $candidate) {
            if ($candidate instanceof Message && $candidate->thread === $absorbed) {
                $survivor->addMessage($candidate);
                $candidate->thread = $survivor;
                $moved[]           = $candidate;
            }
        }

        // Without this the card above the conversation loses its link: the
        // foreign key sets the insight's thread to NULL when the row goes.
        if (null !== $absorbed->id) {
            foreach ($this->insightRepository->forThread($absorbed) as $insight) {
                $insight->thread = $survivor;
            }
        }

        return $moved;
    }

    /**
     * Tell JMAP clients, for a thread they can have seen.
     *
     * One without an id was created in this batch and has never been announced,
     * so there is nothing to retract; the messages in it were announced as
     * created moments ago, and the caller announces the thread they ended up in
     * once the batch is flushed. One with an id is destroyed, and every stored
     * message that left it changed its threadId.
     *
     * @param list<Message> $moved
     */
    private function announce(MessageThread $absorbed, MessageThread $survivor, array $moved): void
    {
        $absorbedId = $absorbed->id;
        $accountId  = $survivor->account?->id;

        if (null === $absorbedId || null === $accountId) {
            return;
        }

        foreach ($moved as $message) {
            if (null !== $message->id) {
                $this->changes->emailChanged($accountId, (string) $message->id, created: false, thread: null);
            }
        }

        $this->changes->threadsTouched($accountId, [(int) $survivor->id]);
        $this->changes->threadDestroyed($accountId, $absorbedId);
    }
}
