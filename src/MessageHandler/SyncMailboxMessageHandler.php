<?php

namespace App\MessageHandler;

use App\Message\HarvestContactsMessage;
use App\Message\SyncMailboxMessage;
use App\Repository\MailboxRepository;
use App\Service\Imap\MessageSyncer;
use App\Domain\Helper\ImapConnectionFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly class SyncMailboxMessageHandler
{
    public function __construct(
        private MailboxRepository  $mailboxRepository,
        private MessageSyncer      $messageSyncer,
        private HubInterface       $hub,
        private LoggerInterface    $logger,
        private MessageBusInterface $bus,
        private ImapConnectionFactory $imapConnectionFactory,
    ) {}

    public function __invoke(SyncMailboxMessage $message): void
    {
        $mailbox = $this->mailboxRepository->find($message->mailboxId);

        if ($mailbox === null) {
            $this->logger->info('Mailbox not found', ['mailboxId' => $message->mailboxId]);
            return;
        }

        if (false === $mailbox->isSyncEnabled()) {
            $this->logger->info('Mailbox sync disabled', ['mailboxId' => $message->mailboxId]);
            return;
        }

        $account = $mailbox->getAccount();
        $client  = $this->imapConnectionFactory->connect($account);
        $this->messageSyncer->syncMailbox($mailbox, $client);
        $client->disconnect();

        // Harvest contact addresses from newly synced messages.
        $this->bus->dispatch(new HarvestContactsMessage($message->mailboxId));

        $this->logger->info('Publishing Mercure update', ['mailboxId' => $message->mailboxId]);

        $userId = $account->getUsr()->getId();

        $this->hub->publish(new Update(
            topics: [
                sprintf('mail/user/%d', $userId),
                sprintf('mail/mailbox/%d', $mailbox->getId()),
            ],
            data: json_encode([
                'type'       => 'mailbox.synced',
                'mailboxId'  => $mailbox->getId(),
                'accountId'  => $account->getId(),
                'specialUse' => $mailbox->getSpecialUse(),
            ]),
        ));

        $this->logger->info('Mercure update published', ['mailboxId' => $message->mailboxId]);
    }
}
