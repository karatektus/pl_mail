<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
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
                sprintf('mail/user/%d', $account->usr->id),
                sprintf('mail/mailbox/%d', $mailbox->id),
            ],
            data: json_encode([
                'type' => 'mailbox.synced',
                'mailboxId' => $mailbox->id,
                'accountId' => $account->id,
                'specialUse' => $mailbox->specialUse,
            ]),
        ));
    }

    public function publishAccountSynced(Account $account): void
    {
        $this->hub->publish(new Update(
            topics: [
                sprintf('mail/user/%d', $account->usr->id),
            ],
            data: json_encode([
                'type' => 'account.synced',
                'accountId' => $account->id,
            ]),
        ));
    }
}
