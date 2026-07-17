<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Domain\Interface\AccountSyncerInterface;
use App\Entity\Account;
use App\Repository\MailboxRepository;
use Psr\Log\LoggerInterface;

final readonly class ImapAccountSyncer implements AccountSyncerInterface
{
    public function __construct(
        private MailboxRepository     $mailboxRepository,
        private MessageSyncer         $messageSyncer,
        private ImapConnectionFactory $imapConnectionFactory,
        private LoggerInterface       $logger,
    ) {}

    public function supports(Account $account): bool
    {
        return false === $account->isGmail(); //TODO: lets do a better check for ->isImap() maybe Account should get an explicit accountType field with an AccountType enum
    }

    public function sync(Account $account): array
    {
        $mailboxes = $this->mailboxRepository->findBy([
            'account'       => $account,
            'isSyncEnabled' => true,
        ]);

        if (count($mailboxes) === 0) {
            $this->logger->info('ImapAccountSyncer: no sync-enabled mailboxes', [
                'accountId' => $account->getId(),
            ]);

            return [];
        }

        $client           = $this->imapConnectionFactory->connect($account);
        $syncedMailboxIds = [];

        try {
            foreach ($mailboxes as $mailbox) {
                try {
                    $this->messageSyncer->syncMailbox($mailbox, $client);
                    $syncedMailboxIds[] = $mailbox->getId();
                } catch (\Throwable $e) {
                    $this->logger->error('ImapAccountSyncer: mailbox sync failed', [
                        'mailboxId' => $mailbox->getId(),
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            $client->disconnect();
        }

        return $syncedMailboxIds;
    }
}
