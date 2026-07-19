<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Entity\Account;
use App\Message\SyncGmailMessageBatchMessage;
use App\Repository\MessageRepository;
use App\Service\Mail\GmailApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Plans Gmail sync work and fans it out to SyncGmailMessageBatchMessage jobs.
 *
 * Label-based architecture: the planner operates on the Account directly —
 * Gmail accounts have no Mailbox rows anymore. Each batch handler resolves
 * a message's labelIds to Label entities via GmailLabelResolver and filters
 * out messages not addressed to the owning account via GmailAddressFilter.
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
    public function initialSync(Account $account): void
    {
        $this->logger->info('GmailApiSyncer: planning initial sync', [
            'accountId' => $account->getId(),
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

        $this->dispatchBatches($account, $this->newGmailIds($account, $messageRefs));
    }

    /**
     * Read history since the stored historyId, dispatch batches for newly-added
     * messages, and advance the stored historyId.
     */
    public function syncIncremental(Account $account): void
    {
        $startHistoryId = $account->getGmailHistoryId();

        if (null === $startHistoryId) {
            $this->logger->warning('GmailApiSyncer: no historyId stored, running initial sync', [
                'accountId' => $account->getId(),
            ]);
            $this->initialSync($account);

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
                    'accountId' => $account->getId(),
                ]);
                $account->setGmailHistoryId(null);
                $this->em->flush();
                $this->initialSync($account);

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

        $this->dispatchBatches($account, $this->newGmailIds($account, $refs));

        $account->setGmailHistoryId((string) $result['historyId']);
        $this->em->flush();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param list<array{id?: string}> $refs
     * @return list<string>
     */
    private function newGmailIds(Account $account, array $refs): array
    {
        $syncedGmailIds = array_flip(
            $this->messageRepository->findSyncedGmailIdsForUser($account->getUsr())
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
    private function dispatchBatches(Account $account, array $gmailIds): void
    {
        if (count($gmailIds) === 0) {
            return;
        }

        $batches = array_chunk($gmailIds, self::BATCH_SIZE);

        foreach ($batches as $batch) {
            $this->bus->dispatch(new SyncGmailMessageBatchMessage(
                (int) $account->getId(),
                $batch,
            ));
        }

        $this->logger->info('GmailApiSyncer: batches dispatched', [
            'accountId' => $account->getId(),
            'messages'  => count($gmailIds),
            'batches'   => count($batches),
        ]);
    }
}
