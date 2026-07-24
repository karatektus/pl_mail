<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Entity\Account;
use App\Message\SyncGraphMessageBatchMessage;
use App\Repository\MessageRepository;
use App\Service\Mail\GraphApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Plans Graph sync work and fans it out to SyncGraphMessageBatchMessage jobs.
 *
 * Unlike Gmail there is no initial/incremental split: a delta query with no
 * stored deltaLink enumerates the whole folder and hands back a link, and the
 * same call with a link returns only changes. One code path covers both.
 *
 * Delta state is per FOLDER, not per account (Gmail's single historyId has no
 * equivalent here), so it lives in a folderId => deltaLink map on the Account.
 *
 * Message movement is visible but ambiguous: moving a message out of a folder
 * shows up as `@removed` on the source folder's delta and as an addition on
 * the destination folder's delta. With immutable ids both carry the SAME id,
 * so the two halves reconcile — the label is detached on one side and attached
 * on the other, and no body refetch is needed because delta already carries
 * parentFolderId.
 */
final class GraphApiSyncer
{
    /** Graph message ids per fan-out batch — the $batch sub-request ceiling. */
    private const int BATCH_SIZE = GraphApiClient::BATCH_LIMIT;

    public function __construct(
        private readonly GraphApiClient         $apiClient,
        private readonly GraphFolderResolver    $folderResolver,
        private readonly MessageRepository      $messageRepository,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface    $bus,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * @param list<string> $folderIds
     */
    public function sync(Account $account, array $folderIds): void
    {
        $deltaLinks = $account->getGraphDeltaLinks();
        $pending    = [];

        foreach ($folderIds as $folderId) {
            $storedLink = $deltaLinks[$folderId] ?? null;

            try {
                $result = $this->apiClient->deltaMessages($account, $folderId, $storedLink);
            } catch (\Throwable $e) {
                $this->logger->error('GraphApiSyncer: delta failed', [
                    'accountId' => $account->getId(),
                    'folderId'  => $folderId,
                    'error'     => $e->getMessage(),
                ]);

                continue;
            }

            if (true === $result['resyncRequired']) {
                $this->logger->warning('GraphApiSyncer: delta token expired, re-enumerating folder', [
                    'accountId' => $account->getId(),
                    'folderId'  => $folderId,
                ]);

                unset($deltaLinks[$folderId]);
                $account->setGraphDeltaLinks($deltaLinks);
                $this->em->flush();

                try {
                    $result = $this->apiClient->deltaMessages($account, $folderId, null);
                } catch (\Throwable $e) {
                    $this->logger->error('GraphApiSyncer: re-enumeration failed', [
                        'accountId' => $account->getId(),
                        'folderId'  => $folderId,
                        'error'     => $e->getMessage(),
                    ]);

                    continue;
                }
            }

            foreach ($this->partition($account, $folderId, $result['items']) as $graphId) {
                $pending[$graphId] = true;
            }

            if (null !== $result['deltaLink']) {
                $deltaLinks[$folderId] = $result['deltaLink'];
            }
        }

        $account->setGraphDeltaLinks($deltaLinks);
        $this->em->flush();

        $this->dispatchBatches($account, array_keys($pending));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Split a folder's delta payload into work that needs a full fetch and work
     * that can be settled inline.
     *
     * Returns only the ids that still need their bodies pulled; relabelling of
     * already-synced messages is applied here and then flushed by the caller.
     *
     * @param list<array<string,mixed>> $items
     * @return list<string>
     */
    private function partition(Account $account, string $folderId, array $items): array
    {
        $known = array_flip(
            $this->messageRepository->findSyncedGraphIdsForUser($account->getUsr())
        );

        $needsFetch = [];

        foreach ($items as $item) {
            $graphId = (string) ($item['id'] ?? '');

            if ('' === $graphId) {
                continue;
            }

            if (true === array_key_exists('@removed', $item)) {
                $this->detachFolderLabel($account, $graphId, $folderId);
                continue;
            }

            if (false === array_key_exists($graphId, $known)) {
                $needsFetch[] = $graphId;
                continue;
            }

            // Already synced and still present — the only thing that can have
            // changed cheaply is where it lives.
            $this->attachFolderLabel($account, $graphId, (string) ($item['parentFolderId'] ?? $folderId));
        }

        return $needsFetch;
    }

    private function attachFolderLabel(Account $account, string $graphId, string $folderId): void
    {
        $message = $this->messageRepository->findOneBy(['graphId' => $graphId]);

        if (null === $message) {
            return;
        }

        $label = $this->folderResolver->resolveFolder($folderId, $account);

        if (null === $label) {
            return;
        }

        $message->addLabel($label);

        $thread = $message->getThread();

        if (null !== $thread) {
            $thread->addLabel($label);
        }
    }

    private function detachFolderLabel(Account $account, string $graphId, string $folderId): void
    {
        $message = $this->messageRepository->findOneBy(['graphId' => $graphId]);

        if (null === $message) {
            return;
        }

        $label = $this->folderResolver->resolveFolder($folderId, $account);

        if (null === $label) {
            return;
        }

        $message->removeLabel($label);
    }

    /**
     * @param list<string> $graphIds
     */
    private function dispatchBatches(Account $account, array $graphIds): void
    {
        if (count($graphIds) === 0) {
            $this->logger->info('GraphApiSyncer: nothing new to fetch', [
                'accountId' => $account->getId(),
            ]);

            return;
        }

        $chunks = array_chunk($graphIds, self::BATCH_SIZE);

        foreach ($chunks as $chunk) {
            $this->bus->dispatch(new SyncGraphMessageBatchMessage(
                (int) $account->getId(),
                array_values($chunk),
            ));
        }

        $this->logger->info('GraphApiSyncer: dispatched message batches', [
            'accountId' => $account->getId(),
            'messages'  => count($graphIds),
            'batches'   => count($chunks),
        ]);
    }
}
