<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Entity\MessageThread;

/**
 * Keeps a thread's labels equal to the union of its messages' labels
 * (Gmail semantics: a thread appears under a label if ANY message in it
 * carries that label).
 *
 * Called after any message-level label mutation and by MessageThreader
 * when attaching newly-synced messages.
 */
final class ThreadLabelSynchronizer
{
    public function sync(MessageThread $thread): void
    {
        $union = [];

        foreach ($thread->getMessages() as $message) {
            foreach ($message->getLabels() as $label) {
                $union[(int) $label->id] = $label;
            }
        }

        // Remove thread labels no message carries anymore.
        foreach ($thread->getLabels() as $threadLabel) {
            if (false === array_key_exists((int) $threadLabel->id, $union)) {
                $thread->removeLabel($threadLabel);
            }
        }

        // Add labels the thread is missing.
        foreach ($union as $label) {
            $thread->addLabel($label);
        }
    }
}
