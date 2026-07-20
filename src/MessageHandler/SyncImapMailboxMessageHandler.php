<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Helper\ImapConnectionFactory;
use App\Message\HarvestContactsMessage;
use App\Message\SyncImapMailboxMessage;
use App\Repository\MailboxRepository;
use App\Service\Imap\MessageSyncer;
use App\Service\Mail\SyncNotifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class SyncImapMailboxMessageHandler
{
    public function __construct(
        private MailboxRepository     $mailboxRepository,
        private MessageSyncer         $messageSyncer,
        private ImapConnectionFactory $imapConnectionFactory,
        private SyncNotifier          $syncNotifier,
        private MessageBusInterface   $bus,
        private LoggerInterface       $logger,
    ) {}

    public function __invoke(SyncImapMailboxMessage $message): void
    {
        $mailbox = $this->mailboxRepository->find($message->mailboxId);

        if (null === $mailbox) {
            $this->logger->info('Mailbox not found', ['mailboxId' => $message->mailboxId]);
            return;
        }

        if (false === $mailbox->isSyncEnabled()) {
            $this->logger->info('Mailbox sync disabled', ['mailboxId' => $message->mailboxId]);
            return;
        }

        $client = $this->imapConnectionFactory->connect($mailbox->getAccount());

        try {
            $this->messageSyncer->syncMailbox($mailbox, $client);
        } finally {
            $client->disconnect();
        }

        // MessageSyncer clears the EntityManager mid-run, so reload before notifying.
        $mailbox = $this->mailboxRepository->find($message->mailboxId);

        if (null === $mailbox) {
            return;
        }

        $account = $mailbox->getAccount();

        $this->syncNotifier->publishMailboxSynced($account, $mailbox);
        $this->bus->dispatch(new HarvestContactsMessage((int) $account->getId()));
    }
}
