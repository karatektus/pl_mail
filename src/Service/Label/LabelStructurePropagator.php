<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Entity\Account;
use App\Entity\Label;
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
     */
    public function renamed(Label $label): void
    {
        $this->dispatch($label, ApplyLabelStructureMessage::ACTION_RENAME);
    }

    /**
     * MUST be called before the row is removed: the remote id and name are
     * captured from the entity here, and there is nothing to read afterwards.
     */
    public function deleted(Label $label): void
    {
        $this->dispatch($label, ApplyLabelStructureMessage::ACTION_DELETE);
    }

    private function dispatch(Label $label, string $action): void
    {
        $account = $label->account;

        if (null === $account || false === $this->isEnabled($account)) {
            return;
        }

        // System labels map onto provider built-ins (INBOX, SENT, …) that
        // cannot be created, renamed or deleted through the API.
        if (true === $label->isSystem) {
            return;
        }

        $this->bus->dispatch(new ApplyLabelStructureMessage(
            accountId: (int) $account->getId(),
            action: $action,
            labelId: $label->id,
            fullName: $label->fullName,
            remoteId: $this->remoteIdOf($label),
            parentRemoteId: null === $label->parent ? null : $this->remoteIdOf($label->parent),
        ));
    }

    private function isEnabled(Account $account): bool
    {
        return true === $account->isLabelSyncEnabled();
    }

    private function remoteIdOf(Label $label): ?string
    {
        return $label->gmailLabelId ?? $label->graphFolderId;
    }
}
