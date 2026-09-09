<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Exception\OAuthGrantRevokedException;
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
        } catch (OAuthGrantRevokedException $revoked) {
            // RECORDED AND SWALLOWED, and it is the one failure here that is.
            //
            // The grant is gone: the user revoked access, changed the password
            // behind it, or let it age out. Nothing this process does brings it
            // back, and OAuthTokenManager has already written the failure onto
            // the account, which is what raises the red "needs you to sign in
            // again" card with the Reconnect button on it. Everything a person
            // needs has already happened by the time we get here.
            //
            // Rethrowing added exactly two things, both bad. Messenger logs an
            // escaped handler exception at CRITICAL with the whole nested trace
            // — which is the top log level, spent on a condition that is not a
            // fault in this application and that an administrator can do
            // nothing about. And the envelope goes to the failure transport, so
            // every sync cycle left another dead job behind: fifty of them on
            // one account, all the same sentence, sitting under "background
            // jobs were given up on" as though something needed retrying.
            //
            // UnrecoverableExceptionInterface on the exception was half of this
            // — it stops the retry ladder, so one CRITICAL per cycle instead of
            // four — but Messenger still logs and still files the envelope. The
            // other half is not letting it escape.
            //
            // `notice`, not `warning`: this has a first-class surface in the
            // interface already, and repeating it at a level the log browser
            // shows by default would put the same unactionable line in front of
            // an administrator on every cycle for as long as the account stays
            // disconnected. It is here so the sequence can be reconstructed,
            // not so anybody is told twice.
            $this->recordFailure($message->accountId, $revoked);

            $this->logger->notice('Sync stopped: this account needs reconnecting', [
                'accountId' => $message->accountId,
            ]);

            return;
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
                'exception' => $bookkeeping,
            ]);
        }
    }
}
