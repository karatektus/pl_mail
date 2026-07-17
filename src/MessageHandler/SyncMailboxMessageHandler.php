<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Enum\MailProvider;
use App\Entity\Account;
use App\Entity\Mailbox;
use App\Enum\AuthType;
use App\Message\HarvestContactsMessage;
use App\Message\SyncMailboxMessage;
use App\Repository\MailboxRepository;
use App\Service\Gmail\GmailApiSyncer;
use App\Service\Imap\MessageSyncer;
use App\Domain\Helper\ImapConnectionFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class SyncMailboxMessageHandler
{
    public function __construct(
        private MailboxRepository     $mailboxRepository,
        private MessageSyncer         $messageSyncer,
        private GmailApiSyncer        $gmailApiSyncer,
        private HubInterface          $hub,
        private LoggerInterface       $logger,
        private MessageBusInterface   $bus,
        private ImapConnectionFactory $imapConnectionFactory,
    ) {}

    public function __invoke(SyncMailboxMessage $message): void
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

        $account = $mailbox->getAccount();

        if (true === $this->isGmailAccount($account)) {
            $this->syncViaGmailApi($mailbox);
        } else {
            $this->syncViaImap($mailbox);
        }

        // Harvest contact addresses from newly synced messages
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

    // ── Private ───────────────────────────────────────────────────────────────

    private function isGmailAccount(Account $account): bool
    {
        return true === (
                AuthType::OAuth2->value === $account->getAuthType()
                && MailProvider::Google->value === $account->getOauthProvider()
            );
    }

    private function syncViaGmailApi(Mailbox $mailbox): void
    {
        $this->logger->info('Routing to GmailApiSyncer', ['mailboxId' => $mailbox->getId()]);

        if (null === $mailbox->getGmailHistoryId()) {
            $this->gmailApiSyncer->initialSync($mailbox);
        } else {
            $this->gmailApiSyncer->syncIncremental($mailbox);
        }
    }

    private function syncViaImap(Mailbox $mailbox): void
    {
        $this->logger->info('Routing to MessageSyncer (IMAP)', ['mailboxId' => $mailbox->getId()]);

        $client = $this->imapConnectionFactory->connect($mailbox->getAccount());
        $this->messageSyncer->syncMailbox($mailbox, $client);
        $client->disconnect();
    }
}
