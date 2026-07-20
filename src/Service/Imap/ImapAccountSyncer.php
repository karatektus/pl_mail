<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Domain\Interface\AccountSyncerInterface;
use App\Entity\Account;
use App\Repository\MailboxRepository;
use Psr\Log\LoggerInterface;

/**
 * Full IMAP account sync: folder discovery first (so new folders created by
 * other clients appear and get their label chains), then message sync per
 * sync-enabled mailbox. app:mail:sync is therefore the single entry point
 * for both structure and content.
 */
final readonly class ImapAccountSyncer implements AccountSyncerInterface
{
    public function __construct(
        private MailboxRepository     $mailboxRepository,
        private MailboxSyncer         $mailboxSyncer,
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
        try {
            $structure = $this->mailboxSyncer->syncForAccount($account);

            $this->logger->info('ImapAccountSyncer: mailbox structure synced', [
                'accountId' => $account->getId(),
                'created'   => $structure['created'],
                'updated'   => $structure['updated'],
                'deleted'   => $structure['deleted'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('ImapAccountSyncer: mailbox structure sync failed', [
                'accountId' => $account->getId(),
                'error'     => $e->getMessage(),
            ]);
        }

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
