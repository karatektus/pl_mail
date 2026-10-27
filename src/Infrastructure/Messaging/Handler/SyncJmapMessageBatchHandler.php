<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Infrastructure\Messaging\Message\SyncJmapMessageBatchMessage;
use App\Repository\Mail\AccountRepository;
use App\Service\JmapClient\JmapEmailSyncer;
use App\Service\Mail\SyncOrigin;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Fetches and stores one batch of JMAP Emails.
 *
 * Thin on purpose: what a batch means lives in JmapEmailSyncer::ingest(),
 * which an import page calls too, so the live path and the import store the
 * same row the same way. A JMAP refusal is left to escape: a throttled server
 * is a RecoverableException and gets the ingest queue's retry ladder, and an
 * Email that cannot be fetched is skipped inside the batch rather than
 * failing it.
 */
#[AsMessageHandler]
final readonly class SyncJmapMessageBatchHandler
{
    public function __construct(
        private AccountRepository $accounts,
        private JmapEmailSyncer   $emails,
        private SyncOrigin        $origin,
        private LoggerInterface   $logger,
    ) {
    }

    public function __invoke(SyncJmapMessageBatchMessage $message): void
    {
        $this->origin->during($message->trigger ?? null, function () use ($message): void {
            $account = $this->accounts->find($message->accountId);

            if (null === $account || true !== $account->isActive || false === $account->isJmap()) {
                $this->logger->info('SyncJmapMessageBatch: account gone, inactive or no longer JMAP', [
                    'accountId' => $message->accountId,
                ]);

                return;
            }

            $this->emails->ingest($account, $message->emailIds);
        });
    }
}
