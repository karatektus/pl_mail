<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Interface\AccountSyncerInterface;
use App\Entity\Account;
use App\Message\SyncAccountMessage;
use App\Repository\AccountRepository;
use App\Repository\MailboxRepository;
use App\Service\Mail\SyncNotifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncAccountMessageHandler
{
    /**
     * @param iterable<AccountSyncerInterface> $syncers
     */
    public function __construct(
        private AccountRepository $accountRepository,
        private MailboxRepository $mailboxRepository,
        private SyncNotifier      $syncNotifier,
        private LoggerInterface   $logger,
        #[AutowireIterator('app.account_syncer')]
        private iterable          $syncers,
    ) {}

    public function __invoke(SyncAccountMessage $message): void
    {
        $account = $this->accountRepository->find($message->accountId);

        if (null === $account) {
            $this->logger->info('Account not found', ['accountId' => $message->accountId]);
            return;
        }

        if (true !== $account->isActive()) {
            $this->logger->info('Account inactive', ['accountId' => $message->accountId]);
            return;
        }

        $syncer = $this->resolveSyncer($account);

        if (null === $syncer) {
            $this->logger->warning('No syncer supports account', ['accountId' => $message->accountId]);
            return;
        }

        $mailboxIds = $syncer->sync($account);

        // A sync clears the EntityManager mid-run, so reload the account managed.
        $account = $this->accountRepository->find($message->accountId);

        if (null === $account) {
            return;
        }

        foreach ($mailboxIds as $mailboxId) {
            $mailbox = $this->mailboxRepository->find($mailboxId);

            if (null === $mailbox) {
                continue;
            }

            $this->syncNotifier->notifyMailboxSynced($account, $mailbox);
        }
    }

    private function resolveSyncer(Account $account): ?AccountSyncerInterface
    {
        foreach ($this->syncers as $syncer) {
            if (true === $syncer->supports($account)) {
                return $syncer;
            }
        }

        return null;
    }
}
