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
 * Unconditional on every provider that has somewhere to put a label. It was
 * opt-in per account and off by default, on the reasoning that plMail had never
 * created labels remotely and starting to would surprise anyone with an
 * established Gmail label set. What that actually bought was an account whose
 * organisation existed only in plMail and vanished the moment its owner opened
 * Gmail — mirroring is what the rest of the sync does in both directions, and
 * labels were the one thing that quietly did not.
 *
 * IMAP is still excluded, and that exclusion is the part worth keeping: there a
 * "label" is a physical folder, so creating and deleting them means moving real
 * mail around on the server. See isEnabled().
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
     * own remote id and its own provider check.
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

    /**
     * Whether this account's provider can be told about a label at all.
     *
     * There used to be a second condition here — a per-account toggle, off by
     * default — and it was the wrong shape for what plMail is. An account whose
     * labels exist only in plMail is an account whose organisation is lost the
     * moment the user opens the provider's own client, which is not a mode
     * worth offering and certainly not one worth defaulting to. Mirroring is
     * what the rest of the sync does in both directions; labels were the one
     * thing that quietly did not.
     *
     * What remains is a capability rather than a preference. On Gmail a label
     * is a label and on Exchange it is a folder, both of which this can create;
     * on plain IMAP a label is a physical folder, so the same operations would
     * move real mail on the server, which is a different and riskier thing than
     * anything dispatched from here means.
     */
    private function isEnabled(Account $account): bool
    {
        return true === $account->supportsLabelSync();
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
