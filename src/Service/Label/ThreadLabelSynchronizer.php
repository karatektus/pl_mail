<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps a thread's labels equal to the union of its messages' labels
 * (Gmail semantics: a thread appears under a label if ANY message in it
 * carries that label).
 *
 * Called after any message-level label mutation and by MessageThreader
 * when attaching newly-synced messages.
 *
 * "Its messages" means the messages that point at the thread now, in memory,
 * and not what $thread->messages holds. The threader attaches a message on the
 * owning side only, and the rules label new mail after threading and before
 * the batch's flush: at that moment the collection of a thread opened in the
 * batch is empty, and that of a stored thread is loaded from a database where
 * the new row has no thread yet. Taking the union over the collection alone
 * removed the label a rule had just put on a new message from its thread.
 *
 * Fixed here rather than by having the threader add every message to the
 * collection: adding to an uninitialised collection loads it, which would
 * hydrate the whole conversation for every reply a sync brings in. This
 * method loads it anyway, and only runs when a label has changed.
 */
final readonly class ThreadLabelSynchronizer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function sync(MessageThread $thread): void
    {
        $union = [];

        foreach ($this->messagesOf($thread) as $message) {
            foreach ($message->labels as $label) {
                $union[(int) $label->id] = $label;
            }
        }

        // Remove thread labels no message carries anymore.
        foreach ($thread->labels as $threadLabel) {
            if (false === array_key_exists((int) $threadLabel->id, $union)) {
                $thread->removeLabel($threadLabel);
            }
        }

        // Add labels the thread is missing.
        foreach ($union as $label) {
            $thread->addLabel($label);
        }
    }

    /**
     * The stored messages, plus whatever the unit of work holds for this
     * thread that the database does not know about yet, minus any that have
     * since been moved to another thread in memory.
     *
     * New rows are not in the identity map until they are flushed, hence the
     * scheduled insertions beside it.
     *
     * @return array<int, Message> keyed by object id, so a message found both
     *                             ways counts once
     */
    private function messagesOf(MessageThread $thread): array
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $messages   = [];

        foreach ([
            $thread->messages,
            $unitOfWork->getIdentityMap()[Message::class] ?? [],
            $unitOfWork->getScheduledEntityInsertions(),
        ] as $candidates) {
            foreach ($candidates as $candidate) {
                if ($candidate instanceof Message && $candidate->thread === $thread) {
                    $messages[spl_object_id($candidate)] = $candidate;
                }
            }
        }

        return $messages;
    }
}
