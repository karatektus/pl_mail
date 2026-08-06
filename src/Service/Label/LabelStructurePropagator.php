<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Entity\Mail\Account;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Infrastructure\Messaging\Message\ApplyLabelStructureMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Pushes label *structure* changes — create, rename, delete of the label
 * itself — out to the account's provider.
 *
 * Distinct from LabelChangePropagator, which attaches and detaches existing
 * labels on messages. Same contract though: the DB is the source of truth, the
 * caller mutates it first, and propagation is async so a slow or broken
 * provider never fails the user's action.
 *
 * Opt-in per account (Account::labelSyncEnabled). Off by default, because
 * until now plMail never created labels remotely and silently starting to do
 * so would surprise anyone with an established Gmail label set.
 *
 * IMAP is deliberately excluded: there a "label" is a physical folder, so
 * creating and deleting them means moving mail around on the server. Only
 * Gmail and Microsoft accounts are offered the toggle.
 */
final readonly class LabelStructurePropagator
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function created(Label $label): void
    {
        $this->dispatch($label, ApplyLabelStructureMessage::ACTION_CREATE);
    }

    /**
     * Gmail encodes hierarchy in the name, so a re-parent is a rename too —
     * both callers land here with the label's new full name.
     *
     * $previousFullName is what the label was called before, and it is not
     * optional in spirit even though it is in the signature. An Exchange master
     * category has no id anywhere except where plMail happens to have recorded
     * one: its identity at the provider is its display name. Without the old
     * name, a rename of a category that came *from* Outlook has nothing to
     * address — which is exactly how renaming one used to end up creating a
     * second category under the new name and leaving the first standing.
     */
    public function renamed(Label $label, ?string $previousFullName = null): void
    {
        $this->dispatch($label, ApplyLabelStructureMessage::ACTION_RENAME, $previousFullName);
    }

    /**
     * MUST be called before the row is removed: the remote id and name are
     * captured from the entity here, and there is nothing to read afterwards.
     */
    public function deleted(Label $label): void
    {
        $this->dispatch($label, ApplyLabelStructureMessage::ACTION_DELETE);
    }

    /**
     * A label is now one user-level row materialized on N accounts, so a
     * structure change fans out to one job per binding — each account gets its
     * own remote id and its own labelSyncEnabled check.
     *
     * A label with no bindings has never been used on any account and has
     * nothing to propagate.
     */
    private function dispatch(Label $label, string $action, ?string $previousFullName = null): void
    {
        // System labels map onto provider built-ins (INBOX, SENT, …) that
        // cannot be created, renamed or deleted through the API.
        if (true === $label->isSystem) {
            return;
        }

        foreach ($label->bindings as $binding) {
            $account = $binding->account;

            if (null === $account || false === $this->isEnabled($account)) {
                continue;
            }

            $this->bus->dispatch(new ApplyLabelStructureMessage(
                accountId: (int) $account->id,
                action: $action,
                labelId: $label->id,
                fullName: $label->fullName,
                remoteId: $this->remoteIdOf($binding),
                parentRemoteId: $this->remoteIdOf($label->parent?->bindingFor($account)),
                // Sent beside the folder id rather than folded into it. The two
                // are different things at the provider and the handler picks
                // between them by asking GraphLabelPolicy which shape this
                // label takes — see LabelBinding for what conflating them did.
                categoryRemoteId: $binding->graphCategoryId,
                previousFullName: $previousFullName,
            ));
        }
    }

    private function isEnabled(Account $account): bool
    {
        return true === $account->isLabelSyncEnabled();
    }

    /**
     * The id of the thing this label IS at the provider — a Gmail label, or an
     * Exchange folder. Deliberately not the master category id, which travels
     * separately: a category is a tag rather than a place, and the handler must
     * not be able to address one as the other.
     */
    private function remoteIdOf(?LabelBinding $binding): ?string
    {
        return $binding?->gmailLabelId ?? $binding?->graphFolderId;
    }
}
