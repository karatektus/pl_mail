<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Service\Mail\ThreadStatusUpdater;

/**
 * Keywords another client put on or took off, as labels (#221 step 2).
 *
 * Both inbound flag doors call this after ThreadStatusUpdater::applyRemoteFlags():
 * the folder listing (ImapFlagReconciler) and the line an idling connection is
 * pushed (ApplyRemoteFlagsMessageHandler). One rule, so neither can drift.
 *
 * Read as a CHANGE — the keywords of the row's flag mirror before the update
 * against after it — and never as "the server's set is the truth": a label the
 * person gave a message while keywords were off, or in a folder that keeps
 * none, has no keyword and must not be taken off for lacking one. A row the
 * echo guard held back still has its old mirror, so it shows no change and is
 * left for the next pass.
 *
 * Only where the account keeps labels as keywords. Only tags: a keyword whose
 * label is a folder on this account is not a place, and changes nothing here.
 */
final readonly class KeywordLabelFollower
{
    public function __construct(
        private KeywordLabels       $labels,
        private ThreadStatusUpdater $status,
    ) {
    }

    /**
     * @param iterable<Message>        $messages after applyRemoteFlags()
     * @param array<int, list<string>> $before   message id => flags before it
     */
    public function follow(Mailbox $mailbox, iterable $messages, array $before): void
    {
        $account = $mailbox->account;
        $owner   = $account?->usr;

        if (null === $account || null === $owner || false === $account->labelsAsKeywords) {
            return;
        }

        $touched = [];

        foreach ($messages as $message) {
            $old = KeywordLabels::namesIn($before[(int) $message->id] ?? []);
            $new = KeywordLabels::namesIn($message->flags);

            if ($old === $new) {
                continue;
            }

            foreach (array_diff($new, $old) as $name) {
                $label = $this->labels->label($name, $owner, true);

                if (null !== $label && null === $label->bindingFor($account)?->mailbox) {
                    $message->addLabel($label);
                    $touched[(int) $message->id] = $message;
                }
            }

            foreach (array_diff($old, $new) as $name) {
                $label = $this->labels->label($name, $owner, false);

                if (null !== $label && null === $label->role && null === $label->bindingFor($account)?->mailbox) {
                    $message->removeLabel($label);
                    $touched[(int) $message->id] = $message;
                }
            }
        }

        $this->status->labelsChangedOnServer(array_values($touched));
    }
}
