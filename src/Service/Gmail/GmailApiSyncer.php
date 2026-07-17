<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Entity\Mailbox;
use App\Message\SyncGmailMessageBatchMessage;
use App\Repository\MessageRepository;
use App\Service\Mail\GmailApiClient;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Plans Gmail sync work and fans it out to SyncGmailMessageBatchMessage jobs.
 *
 * No label filter is applied here — all mail is fetched regardless of label.
 * Each batch handler then routes individual messages to the correct local
 * mailbox based on the message's labelIds via GmailLabelMailboxRouter, and
 * filters out messages not addressed to the owning account via
 * GmailAddressFilter.
 *
 * The $mailbox passed in is used only as the account carrier and as the
 * fallback mailbox for the batch handler; actual routing happens per-message.
 */
final class GmailApiSyncer
{
    /** Message-list page size (API max is 500). */
    private const int PAGE_SIZE = 500;

    /** Gmail message IDs per fan-out batch. */
    private const int BATCH_SIZE = 100;

    public function __construct(
        private readonly GmailApiClient         $apiClient,
        private readonly MessageRepository      $messageRepository,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface    $bus,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * Snapshot the historyId up front, list every message ID (all labels),
     * and dispatch batches for the ones we don't already have.
     */
    public function initialSync(Mailbox $mailbox): void
    {
        $account = $mailbox->getAccount();

        $this->logger->info('GmailApiSyncer: planning initial sync', [
            'mailboxId' => $mailbox->getId(),
            'account'   => $account->getEmail(),
        ]);

        $profile          = $this->apiClient->getProfile($account);
        $currentHistoryId = (string) ($profile['historyId'] ?? '');

        if ('' !== $currentHistoryId) {
            $account->setGmailHistoryId($currentHistoryId);
            $this->em->flush();
        }

        // No labelIds filter — fetch all mail (inbox, sent, spam, trash, …).
        $messageRefs = $this->apiClient->listMessages($account, [
            'maxResults' => self::PAGE_SIZE,
        ]);

        $this->dispatchBatches($mailbox, $this->newGmailIds($mailbox, $messageRefs));
    }

    /**
     * Read history since the stored historyId, dispatch batches for newly-added
     * messages, and advance the stored historyId.
     */
    public function syncIncremental(Mailbox $mailbox): void
    {
        $account        = $mailbox->getAccount();
        $startHistoryId = $account->getGmailHistoryId();

        if (null === $startHistoryId) {
            $this->logger->warning('GmailApiSyncer: no historyId stored, running initial sync', [
                'mailboxId' => $mailbox->getId(),
            ]);
            $this->initialSync($mailbox);

            return;
        }

        try {
            // No labelId filter — track additions across all labels.
            $result = $this->apiClient->listHistory($account, $startHistoryId, [
                'historyTypes' => 'messageAdded',
            ]);
        } catch (\Throwable $e) {
            if (
                true === str_contains($e->getMessage(), '404')
                || true === str_contains($e->getMessage(), '410')
            ) {
                $this->logger->warning('GmailApiSyncer: historyId expired, re-running initial sync', [
                    'mailboxId' => $mailbox->getId(),
                ]);
                $account->setGmailHistoryId(null);
                $this->em->flush();
                $this->initialSync($mailbox);

                return;
            }

            throw $e;
        }

        $refs = [];
        foreach ($result['history'] as $record) {
            foreach ($record['messagesAdded'] ?? [] as $added) {
                $id = (string) ($added['message']['id'] ?? '');
                if ('' !== $id) {
                    $refs[] = ['id' => $id];
                }
            }
        }

        $this->dispatchBatches($mailbox, $this->newGmailIds($mailbox, $refs));

        $account->setGmailHistoryId((string) $result['historyId']);
        $mailbox->setSyncedAt(new DateTimeImmutable());
        $this->em->flush();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param list<array{id?: string}> $refs
     * @return list<string>
     */
    private function newGmailIds(Mailbox $mailbox, array $refs): array
    {
        $syncedGmailIds = array_flip(
            $this->messageRepository->findSyncedGmailIds($mailbox)
        );

        $pending = [];
        foreach ($refs as $ref) {
            $gmailId = (string) ($ref['id'] ?? '');
            if ('' === $gmailId) {
                continue;
            }
            if (true === isset($syncedGmailIds[$gmailId])) {
                continue;
            }
            $pending[] = $gmailId;
        }

        return $pending;
    }

    /**
     * @param list<string> $gmailIds
     */
    private function dispatchBatches(Mailbox $mailbox, array $gmailIds): void
    {
        $chunks = array_chunk($gmailIds, self::BATCH_SIZE);

        foreach ($chunks as $chunk) {
            $this->bus->dispatch(new SyncGmailMessageBatchMessage($mailbox->getId(), $chunk));
        }

        $this->logger->info('GmailApiSyncer: fanned out sync', [
            'mailboxId' => $mailbox->getId(),
            'messages'  => count($gmailIds),
            'batches'   => count($chunks),
        ]);
    }
}
