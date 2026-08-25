<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Interface\AccountSyncerInterface;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MailboxRepository;
use App\Service\Mail\SyncNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final readonly class SyncAccountMessageHandler
{
    /**
     * @param iterable<AccountSyncerInterface> $syncers
     */
    public function __construct(
        private AccountRepository   $accountRepository,
        private MailboxRepository   $mailboxRepository,
        private SyncNotifier        $syncNotifier,
        private LoggerInterface     $logger,
        private EntityManagerInterface $entityManager,
        #[AutowireIterator('app.account_syncer')]
        private iterable            $syncers,
    ) {}

    public function __invoke(SyncAccountMessage $message): void
    {
        $account = $this->accountRepository->find($message->accountId);

        if (null === $account) {
            $this->logger->info('Account not found', ['accountId' => $message->accountId]);
            return;
        }

        if (true !== $account->isActive) {
            $this->logger->info('Account inactive', ['accountId' => $message->accountId]);
            return;
        }

        $syncer = $this->resolveSyncer($account);

        if (null === $syncer) {
            $this->logger->warning('No syncer supports account', ['accountId' => $message->accountId]);
            return;
        }

        try {
            $mailboxIds = $syncer->sync($account);
        } catch (Throwable $failure) {
            // Recorded and RETHROWN. The rethrow is what keeps Messenger's
            // retry ladder intact — this is a note in passing, not a decision
            // about whether to try again, and swallowing here would turn every
            // transient blip into a sync that silently never happened.
            //
            // Reloaded first because a syncer clears the EntityManager mid-run,
            // so the instance above may be detached by the time it throws.
            $this->recordFailure($message->accountId, $failure);

            throw $failure;
        }

        // A sync clears the EntityManager mid-run, so reload the account managed.
        $account = $this->accountRepository->find($message->accountId);

        if (null === $account) {
            return;
        }

        // It worked. Until now nothing wrote this at all: `last_synced_at` has
        // been on the account since the first migration, written by nobody and
        // read by nobody, which made a mailbox that had been failing for a week
        // indistinguishable from one that synced a minute ago.
        $account->recordSyncSuccess();
        $this->entityManager->flush();

        foreach ($mailboxIds as $mailboxId) {
            $mailbox = $this->mailboxRepository->find($mailboxId);

            if (null === $mailbox) {
                continue;
            }

            $this->syncNotifier->publishMailboxSynced($account, $mailbox);
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
    /**
     * Note a failed sync on the account, without disturbing the failure itself.
     *
     * Its own EntityManager work, deliberately kept small and separate: this
     * runs on the way out of an exception, and anything it does that throws
     * would replace the real fault with a bookkeeping one.
     */
    private function recordFailure(int $accountId, Throwable $failure): void
    {
        try {
            $account = $this->accountRepository->find($accountId);

            if (null === $account) {
                return;
            }

            $account->recordSyncFailure($failure->getMessage());
            $this->entityManager->flush();
        } catch (Throwable $bookkeeping) {
            $this->logger->error('Could not record a sync failure on the account', [
                'accountId' => $accountId,
                'error'     => $bookkeeping->getMessage(),
            ]);
        }
    }
}
