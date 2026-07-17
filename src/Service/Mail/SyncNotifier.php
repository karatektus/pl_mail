<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Account;
use App\Entity\Mailbox;
use App\Message\HarvestContactsMessage;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class SyncNotifier
{
    public function __construct(
        private HubInterface        $hub,
        private MessageBusInterface $bus,
    ) {}

    public function notifyMailboxSynced(Account $account, Mailbox $mailbox): void
    {
        $this->bus->dispatch(new HarvestContactsMessage($mailbox->getId()));
        $this->publishMailboxSynced($account, $mailbox);
    }

    public function publishMailboxSynced(Account $account, Mailbox $mailbox): void
    {
        $this->hub->publish(new Update(
            topics: [
                sprintf('mail/user/%d', $account->getUsr()->getId()),
                sprintf('mail/mailbox/%d', $mailbox->getId()),
            ],
            data: json_encode([
                'type'       => 'mailbox.synced',
                'mailboxId'  => $mailbox->getId(),
                'accountId'  => $account->getId(),
                'specialUse' => $mailbox->getSpecialUse(),
            ]),
        ));
    }
}
