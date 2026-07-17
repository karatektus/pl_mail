<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Domain\Interface\AccountSyncerInterface;
use App\Entity\Account;
use App\Repository\MailboxRepository;
use Psr\Log\LoggerInterface;

final readonly class GmailAccountSyncer implements AccountSyncerInterface
{
    public function __construct(
        private GmailApiSyncer    $gmailApiSyncer,
        private MailboxRepository $mailboxRepository,
        private LoggerInterface   $logger,
    ) {}

    public function supports(Account $account): bool
    {
        return $account->isGmail();
    }

    public function sync(Account $account): array
    {
        $inbox = $this->mailboxRepository->findOneBy([
            'account'    => $account,
            'specialUse' => '\\Inbox',
        ]);

        if (null === $inbox) {
            $this->logger->warning('GmailAccountSyncer: no inbox mailbox', [
                'accountId' => $account->getId(),
            ]);

            return [];
        }

        if (false === $inbox->isSyncEnabled()) {
            $this->logger->info('GmailAccountSyncer: inbox sync disabled', [
                'accountId' => $account->getId(),
            ]);

            return [];
        }

        if (null === $account->getGmailHistoryId()) {
            $this->gmailApiSyncer->initialSync($inbox);
        } else {
            $this->gmailApiSyncer->syncIncremental($inbox);
        }

        return [$inbox->getId()];
    }
}
