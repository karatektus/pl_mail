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
 * label is a folder on this account, or a system label (Inbox, Archive, …), is
 * not a place, and changes nothing here.
 *
 * The one exception to reading a change is importExisting(), for the moment
 * the switch goes on (#487): see there.
 */
final readonly class KeywordLabelFollower
{
    public function __construct(
        private KeywordLabels       $labels,
        private ThreadStatusUpdater $status,
        private LabelResolver       $resolver,
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
            $old = self::byKey($before[(int) $message->id] ?? []);
            $new = self::byKey($message->flags);

            // Keyed with capitals folded (#487): a keyword the server now
            // spells `paperless` where the mirror had `Paperless` is no change.
            foreach (array_diff_key($new, $old) as $name) {
                $label = $this->labels->label($name, $owner, true);

                // Not a system label either: now that capitals are ignored, a
                // client's `archive` keyword would otherwise find Archive.
                if (null !== $label && null === $label->role && null === $label->bindingFor($account)?->mailbox) {
                    $this->resolver->usedAsTagOn($label, $account);
                    $message->addLabel($label);
                    $touched[(int) $message->id] = $message;
                }
            }

            foreach (array_diff_key($old, $new) as $name) {
                $label = $this->labels->label($name, $owner, false);

                if (null !== $label && null === $label->role && null === $label->bindingFor($account)?->mailbox) {
                    $message->removeLabel($label);
                    $touched[(int) $message->id] = $message;
                }
            }
        }

        $this->status->labelsChangedOnServer(array_values($touched));
    }

    /**
     * Every keyword these messages already carry, as labels — the read the
     * switch going on owes them (#487).
     *
     * follow() reads a change, and a keyword that was on the server before
     * the switch went on is already in the row's flag mirror: the mirror is
     * kept whether the switch is on or off, so there is no change for it to
     * see, ever. Paperless-ngx's `paperless` on last year's mail would never
     * become a label. So the switch going on reads the mirror once as if it
     * had held no keywords (ImportImapKeywordsHandler).
     *
     * Only adds, by construction: an empty before has nothing to take off. A
     * label already on the message is added again, which changes nothing, so
     * the import can run twice.
     *
     * @param iterable<Message> $messages
     */
    public function importExisting(Mailbox $mailbox, iterable $messages): void
    {
        $this->follow($mailbox, $messages, []);
    }

    /**
     * @param list<string> $flags
     *
     * @return array<string, string> folded name => label full name
     */
    private static function byKey(array $flags): array
    {
        $names = [];

        foreach (KeywordLabels::namesIn($flags) as $name) {
            $names[KeywordLabels::key($name)] = $name;
        }

        return $names;
    }
}
