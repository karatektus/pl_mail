<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Entity\Mail\Account;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;

/**
 * Decides how a plMail label is represented on Exchange.
 *
 * Neither pure strategy works. Pushing everything as categories means
 * archiving in plMail does not archive in Outlook — the message stays in
 * Inbox, because in Exchange location IS the folder. Pushing everything as
 * folder moves destroys the many-to-many model, since applying a second label
 * would move the message off the first.
 *
 * So the split is by whether the label is folder-backed:
 *   - role labels and labels carrying a graphFolderId  → folder move
 *   - everything else                                   → master category
 *
 * A label with a graphFolderId came from a real Exchange folder via
 * GraphFolderSyncer, so a move is the only faithful representation. A label
 * without one is plMail-local, and categories are the only many-to-many
 * primitive Exchange offers.
 *
 * The discriminator needs no new column — it is already in the data. It does
 * need an ACCOUNT, though: graphFolderId lives on LabelBinding since labels
 * became user-scoped, so the same label can be folder-backed on one Exchange
 * account and category-only on another.
 */
final readonly class GraphLabelPolicy
{
    public function pushesAsFolder(Label $label, Account $account): bool
    {
        // A role is folder-backed only where the provider has that folder.
        // Snoozed is plMail's own — nothing on Exchange corresponds to it — so
        // treating it as one sent every snooze looking for a graphFolderId
        // nobody had set ("folder label has no graphFolderId on this account"),
        // and made a snoozed message that is also archived look like it was in
        // two Exchange folders at once, which Exchange cannot represent and
        // hasConflictingLocations duly warned about.
        if (null !== $label->role) {
            return $label->role->hasProviderFolder();
        }

        return null !== $label->bindingFor($account)?->graphFolderId;
    }

    public function pushesAsCategory(Label $label, Account $account): bool
    {
        // A role is never a category. The provider-backed ones are folders,
        // and Snoozed is plMail's own bookkeeping — pushing that as a master
        // category would put a label on somebody's Outlook mailbox that they
        // never made and cannot explain.
        if (null !== $label->role) {
            return false;
        }

        return false === $this->pushesAsFolder($label, $account);
    }

    /**
     * The single folder-backed label a message lives under, or null.
     *
     * Exchange messages live in exactly one folder. If local state somehow
     * holds two folder-backed labels the DB is asserting something Graph
     * cannot represent — delta would keep correcting it, presenting as a
     * phantom bug. exclusiveLocation() picks the highest-priority one and
     * hasConflictingLocations() lets callers detect and repair the state.
     */
    public function exclusiveLocation(Message $message): ?Label
    {
        $candidates = $this->folderLabels($message);

        if (count($candidates) === 0) {
            return null;
        }

        usort($candidates, function (Label $a, Label $b): int {
            return $a->sortOrder <=> $b->sortOrder;
        });


        return $candidates[0];
    }

    public function hasConflictingLocations(Message $message): bool
    {
        return count($this->folderLabels($message)) > 1;
    }

    /**
     * Category names to push, derived from current local state.
     *
     * Graph replaces the whole categories array on PATCH rather than diffing,
     * so the correct payload is always the message's full current set — which
     * also makes the push idempotent.
     *
     * fullName is used rather than the leaf name, so "Work/Invoices" pushes as
     * a category literally called "Work/Invoices". Ugly in Outlook, but
     * collision-free and it round-trips back through
     * LabelResolver::customChain(explode('/', $name)).
     *
     * Note this is deliberately the OPPOSITE of the folder rule:
     * GraphFolderSyncer does not split on "/" because the folder tree is
     * authoritative and a folder genuinely named "A/B" would break. Categories
     * have no tree, so the delimiter convention is all there is.
     *
     * @return list<string>
     */
    public function categoryNames(Message $message): array
    {
        $names   = [];
        $account = $message->account;

        foreach ($message->labels as $label) {
            // Asked positively, so the one label that is neither a folder nor
            // a category — Snoozed — is excluded by the rule that says so.
            if (false === $this->pushesAsCategory($label, $account)) {
                continue;
            }

            $name = (string) $label->fullName;

            if ('' !== $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * The folder-backed labels a message currently holds.
     *
     * Public because the syncer needs it too: a folder move has to take the
     * old location off, and "which of these is a location" is this class's
     * question, not the caller's.
     *
     * @return list<Label>
     */
    public function folderLabels(Message $message): array
    {
        $labels  = [];
        $account = $message->account;

        foreach ($message->labels as $label) {
            if (true === $this->pushesAsFolder($label, $account)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }
}
