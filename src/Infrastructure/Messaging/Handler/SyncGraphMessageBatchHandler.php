<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\SyncGraphMessageBatchMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Graph\GraphMessageBuilder;
use App\Service\HarvestContactsService;
use App\Service\Mail\GraphApiClient;
use App\Service\Mail\PostIngestPipeline;
use App\Service\Mail\SyncNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Imports one chunk of Graph messages.
 *
 * Mirrors SyncGmailMessageBatchHandler, minus the Gmailify attribution
 * machinery: Exchange has no equivalent of Gmail fetching mail for a third
 * -party address, so every message in the batch belongs to the account that
 * fetched it.
 *
 * The retry path matters more here than for Gmail. Graph $batch sub-responses
 * fail individually, and mail throttling is per-mailbox with only ~4 concurrent
 * requests allowed — so a partially-throttled batch is routine, not
 * exceptional. Throttled ids are requeued as their own batch after a delay
 * rather than failing the whole job.
 */
#[AsMessageHandler]
final readonly class SyncGraphMessageBatchHandler
{
    /** Fallback delay when Graph did not send a Retry-After. */
    private const int RETRY_DELAY_MS = 30000;

    public function __construct(
        private MessageRepository      $messageRepository,
        private AccountRepository      $accountRepository,
        private GraphApiClient         $apiClient,
        private GraphMessageBuilder    $messageBuilder,
        private HarvestContactsService $harvestService,
        private SyncNotifier           $syncNotifier,
        private MessageBusInterface    $bus,
        private PostIngestPipeline     $postIngest,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    public function __invoke(SyncGraphMessageBatchMessage $message): void
    {
        $account = $this->accountRepository->find($message->accountId);

        if (null === $account) {
            $this->logger->warning('SyncGraphMessageBatch: account not found', [
                'accountId' => $message->accountId,
            ]);

            return;
        }

        $graphIds = array_values(array_unique($message->graphIds));

        if (count($graphIds) === 0) {
            return;
        }

        $result = $this->apiClient->batchGetMessages($account, $graphIds);

        $this->requeueThrottled($account, $result['throttled']);

        foreach ($result['failed'] as $failedId => $status) {
            $this->logger->error('SyncGraphMessageBatch: sub-request failed', [
                'accountId' => $account->getId(),
                'graphId'   => $failedId,
                'status'    => $status,
            ]);
        }

        $payloads = $result['messages'];

        if (count($payloads) === 0) {
            return;
        }

        // Batches can overlap across runs and retries, so re-check what is
        // already stored. Dedup is USER-scoped and keyed on the RFC Message-ID,
        // not the Graph id — Graph ids are locators that rotate on move when
        // the mailbox does not support immutable ids.
        $attachmentsByMessage = $this->fetchAttachments($account, $payloads);

        $built = [];

        foreach ($payloads as $payload) {
            $graphId      = (string) ($payload['id'] ?? '');
            $rfcMessageId = MessageIdHelper::normalise((string) ($payload['internetMessageId'] ?? ''));

            if ('' !== $rfcMessageId) {
                $existing = $this->messageRepository->findOneForAccountByMessageId($account, $rfcMessageId);

                if (null !== $existing) {
                    // Already have it under a previous (or rotated) address —
                    // re-point the locator and move on.
                    //
                    // Deliberately NOT recorded as a JMAP change: graphId is an
                    // internal locator that appears in no Email property, so a
                    // push here would wake every client for nothing. Label and
                    // flag changes arrive via GraphApiSyncer, which does record.
                    $existing->setGraphId($graphId);
                    continue;
                }
            }

            try {
                $entity = $this->messageBuilder->build(
                    $payload,
                    $account,
                    $attachmentsByMessage[$graphId] ?? [],
                );

                $this->em->persist($entity);

                $built[] = $entity;
            } catch (\Throwable $e) {
                $this->logger->error('SyncGraphMessageBatch: build failed', [
                    'graphId' => '' !== $graphId ? $graphId : '(unknown)',
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->em->flush();

        if (count($built) === 0) {
            return;
        }

        // Exchange has no Gmailify equivalent, so every message in the batch is
        // owned by the account that fetched it.
        $ingested = [];

        foreach ($built as $entity) {
            $ingested[] = new IngestedMessage($entity, $account);
        }

        $result = $this->postIngest->run($account, $ingested);

        $this->harvestService->harvestMessages(
            $account->getUsr(),
            $result->messages,
            $account->getEmail(),
        );

        $this->syncNotifier->publishAccountSynced($account);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * One extra $batch for the messages that actually have attachments —
     * Graph does not inline attachment metadata on the message resource.
     *
     * @param list<array<string,mixed>> $payloads
     * @return array<string, list<array<string,mixed>>>
     */
    private function fetchAttachments(Account $account, array $payloads): array
    {
        $withAttachments = [];

        foreach ($payloads as $payload) {
            if (true !== ($payload['hasAttachments'] ?? false)) {
                continue;
            }

            $graphId = (string) ($payload['id'] ?? '');

            if ('' !== $graphId) {
                $withAttachments[] = $graphId;
            }
        }

        if (count($withAttachments) === 0) {
            return [];
        }

        try {
            return $this->apiClient->batchListAttachments($account, $withAttachments);
        } catch (\Throwable $e) {
            $this->logger->error('SyncGraphMessageBatch: attachment listing failed', [
                'accountId' => $account->getId(),
                'error'     => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param list<string> $throttled
     */
    private function requeueThrottled(Account $account, array $throttled): void
    {
        if (count($throttled) === 0) {
            return;
        }

        $this->logger->info('SyncGraphMessageBatch: requeueing throttled sub-requests', [
            'accountId' => $account->getId(),
            'count'     => count($throttled),
        ]);

        $this->bus->dispatch(
            new SyncGraphMessageBatchMessage((int) $account->getId(), $throttled),
            [new DelayStamp(self::RETRY_DELAY_MS)],
        );
    }
}
