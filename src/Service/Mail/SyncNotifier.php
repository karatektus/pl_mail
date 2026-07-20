<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Account;
use App\Entity\Mailbox;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Mercure publishing only. Contact harvesting is dispatched once per
 * account by the sync handlers (SyncAccountMessageHandler /
 * SyncImapMailboxMessageHandler / SyncGmailMessageBatchHandler harvests
 * inline), not per mailbox here.
 */
final readonly class SyncNotifier
{
    public function __construct(
        private HubInterface $hub,
    )
    {
    }

    public function publishMailboxSynced(Account $account, Mailbox $mailbox): void
    {
        $this->hub->publish(new Update(
            topics: [
                sprintf('mail/user/%d', $account->getUsr()->getId()),
                sprintf('mail/mailbox/%d', $mailbox->getId()),
            ],
            data: json_encode([
                'type' => 'mailbox.synced',
                'mailboxId' => $mailbox->getId(),
                'accountId' => $account->getId(),
                'specialUse' => $mailbox->getSpecialUse(),
            ]),
        ));
    }

    public function publishAccountSynced(Account $account): void
    {
        $this->hub->publish(new Update(
            topics: [
                sprintf('mail/user/%d', $account->getUsr()->getId()),
            ],
            data: json_encode([
                'type' => 'account.synced',
                'accountId' => $account->getId(),
            ]),
        ));
    }
}
