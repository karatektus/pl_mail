<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Domain\Interface\AccountSyncerInterface;
use App\Entity\Account;

/**
 * Gmail sync entry point. Label-based architecture: syncs the label list
 * first so every labelId on incoming messages resolves, then plans message
 * work directly on the account — no Mailbox involvement.
 */
final readonly class GmailAccountSyncer implements AccountSyncerInterface
{
    public function __construct(
        private GmailApiSyncer   $gmailApiSyncer,
        private GmailLabelSyncer $labelSyncer,
    ) {}

    public function supports(Account $account): bool
    {
        return $account->isGmail();
    }

    public function sync(Account $account): array
    {
        $this->labelSyncer->sync($account);

        if (null === $account->getGmailHistoryId()) {
            $this->gmailApiSyncer->initialSync($account);
        } else {
            $this->gmailApiSyncer->syncIncremental($account);
        }

        return [];
    }
}
