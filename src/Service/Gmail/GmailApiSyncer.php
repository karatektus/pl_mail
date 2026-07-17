<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Entity\Mailbox;
use App\Repository\MessageRepository;
use App\Service\Imap\MessageThreader;
use App\Service\Mail\GmailApiClient;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Syncs a Gmail mailbox using the Gmail REST API.
 *
 * Two entry points:
 *   initialSync()      — fetches all messages, stores historyId for future incremental calls
 *   syncIncremental()  — fetches only what changed since the last stored historyId
 *
 * The SyncMailboxMessageHandler decides which to call based on whether a
 * historyId is already stored on the Mailbox.
 */
final class GmailApiSyncer
{
    /** Max messages to fetch per initial-sync page (API max is 500) */
    private const int PAGE_SIZE = 500;

    public function __construct(
        private readonly GmailApiClient    $apiClient,
        private readonly GmailMessageBuilder $messageBuilder,
        private readonly MessageThreader   $messageThreader,
        private readonly MessageRepository $messageRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface   $logger,
    ) {}

    // ── Public entry points ───────────────────────────────────────────────────

    /**
     * Full initial sync: fetch all INBOX message IDs, build Message entities,
     * assign threads, store the current historyId.
     *
     * Safe to call on subsequent runs — already-synced messages are skipped
     * via messageId dedup.
     */
    public function initialSync(Mailbox $mailbox): void
    {
        $account = $mailbox->getAccount();

        $this->logger->info('GmailApiSyncer: starting initial sync', [
            'mailboxId' => $mailbox->getId(),
            'account'   => $account->getEmail(),
        ]);

        // Grab the current historyId before we start so we don't miss anything
        // that arrives while we're paginating
        $profile = $this->apiClient->getProfile($account);
        $currentHistoryId = (string) ($profile['historyId'] ?? '');

        // Load existing message IDs for dedup
        $syncedMessageIds = array_flip(
            $this->messageRepository->findSyncedMessageIds($mailbox)
        );

        $messageRefs = $this->apiClient->listMessages($account, [
            'labelIds'   => 'INBOX',
            'maxResults' => self::PAGE_SIZE,
        ]);

        $this->logger->info('GmailApiSyncer: fetched message list', [
            'count' => count($messageRefs),
        ]);

        $synced = 0;

        foreach ($messageRefs as $ref) {
            $gmailId = (string) ($ref['id'] ?? '');

            if (true === isset($syncedMessageIds[$gmailId])) {
                continue;
            }

            try {
                $payload = $this->apiClient->getMessage($account, $gmailId);
                $message = $this->messageBuilder->build($payload, $mailbox);
                $this->em->persist($message);
                $this->em->flush();

                $this->messageThreader->assignThread($message, $account, $mailbox);
                $this->em->flush();

                $syncedMessageIds[$gmailId] = true;
                $synced++;

                // Flush in small batches to avoid memory pressure
                if (true === ($synced % 50 === 0)) {
                    $this->em->clear();
                    $this->logger->info(sprintf('GmailApiSyncer: synced %d messages', $synced));
                }
            } catch (\Throwable $e) {
                $this->logger->error('GmailApiSyncer: failed to sync message', [
                    'gmailId' => $gmailId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // Store historyId captured at the start — anything arriving after this
        // point will be caught by the next incremental sync
        if ('' !== $currentHistoryId) {
            $mailbox->setGmailHistoryId($currentHistoryId);
        }

        $mailbox->setSyncedAt(new DateTimeImmutable());
        $mailbox->setTotalMessages(
            $this->messageRepository->countTotalForMailbox($mailbox)
        );
        $mailbox->setUnreadMessages(
            $this->messageRepository->countUnseenForMailbox($mailbox)
        );
        $this->em->flush();

        $this->logger->info('GmailApiSyncer: initial sync complete', [
            'synced'    => $synced,
            'historyId' => $currentHistoryId,
        ]);
    }

    /**
     * Incremental sync: fetch only messages added since the last historyId.
     * Updates the stored historyId on completion.
     */
    public function syncIncremental(Mailbox $mailbox): void
    {
        $account        = $mailbox->getAccount();
        $startHistoryId = $mailbox->getGmailHistoryId();

        if (null === $startHistoryId) {
            $this->logger->warning(
                'GmailApiSyncer: no historyId stored, falling back to initial sync',
                ['mailboxId' => $mailbox->getId()],
            );
            $this->initialSync($mailbox);
            return;
        }

        $this->logger->info('GmailApiSyncer: incremental sync', [
            'mailboxId'      => $mailbox->getId(),
            'startHistoryId' => $startHistoryId,
        ]);

        try {
            $result = $this->apiClient->listHistory($account, $startHistoryId, [
                'labelId'         => 'INBOX',
                'historyTypes'    => 'messageAdded',
            ]);
        } catch (\Throwable $e) {
            // historyId too old (410 Gone) — fall back to initial sync
            if (true === str_contains($e->getMessage(), '410')) {
                $this->logger->warning('GmailApiSyncer: historyId expired, re-running initial sync');
                $mailbox->setGmailHistoryId(null);
                $this->em->flush();
                $this->initialSync($mailbox);
                return;
            }

            throw $e;
        }

        $newHistoryId = $result['historyId'];
        $history      = $result['history'];

        // Collect unique message IDs from messagesAdded events
        $gmailIds = [];
        foreach ($history as $record) {
            foreach ($record['messagesAdded'] ?? [] as $added) {
                $id = (string) ($added['message']['id'] ?? '');
                if ('' !== $id) {
                    $gmailIds[$id] = true;
                }
            }
        }

        $this->logger->info('GmailApiSyncer: new messages from history', [
            'count' => count($gmailIds),
        ]);

        // Dedup against already-synced
        $syncedMessageIds = array_flip(
            $this->messageRepository->findSyncedMessageIds($mailbox)
        );

        foreach (array_keys($gmailIds) as $gmailId) {
            if (true === isset($syncedMessageIds[$gmailId])) {
                continue;
            }

            try {
                $payload = $this->apiClient->getMessage($account, $gmailId);
                $message = $this->messageBuilder->build($payload, $mailbox);
                $this->em->persist($message);
                $this->em->flush();

                $this->messageThreader->assignThread($message, $account, $mailbox);
                $this->em->flush();
            } catch (\Throwable $e) {
                $this->logger->error('GmailApiSyncer: failed to sync message', [
                    'gmailId' => $gmailId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // Always advance the stored historyId even if no new messages arrived
        $mailbox->setGmailHistoryId($newHistoryId);
        $mailbox->setSyncedAt(new DateTimeImmutable());
        $mailbox->setUnreadMessages(
            $this->messageRepository->countUnseenForMailbox($mailbox)
        );
        $mailbox->setTotalMessages(
            $this->messageRepository->countTotalForMailbox($mailbox)
        );
        $this->em->flush();

        $this->logger->info('GmailApiSyncer: incremental sync complete', [
            'newHistoryId' => $newHistoryId,
        ]);
    }
}
