<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncGmailMessageBatchMessage;
use App\Repository\MailboxRepository;
use App\Repository\MessageRepository;
use App\Service\Gmail\GmailMessageBuilder;
use App\Service\HarvestContactsService;
use App\Service\Imap\MessageThreader;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\SyncNotifier;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncGmailMessageBatchHandler
{
    public function __construct(
        private MailboxRepository      $mailboxRepository,
        private MessageRepository      $messageRepository,
        private GmailApiClient         $apiClient,
        private GmailMessageBuilder    $messageBuilder,
        private MessageThreader        $messageThreader,
        private HarvestContactsService $harvestService,
        private SyncNotifier           $syncNotifier,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    public function __invoke(SyncGmailMessageBatchMessage $message): void
    {
        $mailbox = $this->mailboxRepository->find($message->mailboxId);

        if (null === $mailbox) {
            $this->logger->warning('SyncGmailMessageBatch: mailbox not found', [
                'mailboxId' => $message->mailboxId,
            ]);
            return;
        }

        $account = $mailbox->getAccount();

        // Dedup inside the batch too — batches can overlap across runs/retries.
        $syncedGmailIds = array_flip(
            $this->messageRepository->findSyncedGmailIds($mailbox)
        );

        $toFetch = [];
        foreach ($message->gmailIds as $gmailId) {
            if (true === isset($syncedGmailIds[$gmailId])) {
                continue;
            }
            $toFetch[] = $gmailId;
        }

        if (count($toFetch) === 0) {
            return;
        }

        $payloads = $this->apiClient->getMessages($account, $toFetch);
        $built    = [];

        foreach ($payloads as $payload) {
            try {
                $entity = $this->messageBuilder->build($payload, $mailbox);
                $this->em->persist($entity);
                $built[] = $entity;
            } catch (\Throwable $e) {
                $this->logger->error('SyncGmailMessageBatch: build failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->em->flush();

        foreach ($built as $entity) {
            try {
                $this->messageThreader->assignThread($entity, $account, $mailbox);
            } catch (\Throwable $e) {
                $this->logger->error('SyncGmailMessageBatch: threading failed', [
                    'messageId' => $entity->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $this->em->flush();

        // Harvest only this batch's messages, not the whole mailbox — keeps the
        // fan-out linear instead of quadratic.
        $this->harvestService->harvestMessages($account->getUsr(), $built);

        $mailbox->setUnreadMessages($this->messageRepository->countUnseenForMailbox($mailbox));
        $mailbox->setTotalMessages($this->messageRepository->countTotalForMailbox($mailbox));
        $mailbox->setSyncedAt(new DateTimeImmutable());
        $this->em->flush();

        // Progressive UI refresh as each batch lands (publish only, no full harvest).
        $this->syncNotifier->publishMailboxSynced($account, $mailbox);
    }
}
