<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Entity\Label;
use App\Entity\Message;

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
 * The discriminator needs no new column — it is already in the data.
 */
final readonly class GraphLabelPolicy
{
    public function pushesAsFolder(Label $label): bool
    {
        if (null !== $label->role) {
            return true;
        }

        return null !== $label->graphFolderId;
    }

    public function pushesAsCategory(Label $label): bool
    {
        return false === $this->pushesAsFolder($label);
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
        $names = [];

        foreach ($message->getLabels() as $label) {
            if (true === $this->pushesAsFolder($label)) {
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
     * @return list<Label>
     */
    private function folderLabels(Message $message): array
    {
        $labels = [];

        foreach ($message->getLabels() as $label) {
            if (true === $this->pushesAsFolder($label)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }
}
