<?php

namespace App\Service\Imap;

use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Mailbox;
use App\Entity\Message;
use App\Entity\MessagePart;
use App\Repository\MailboxRepository;
use App\Repository\MessageRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message as ImapMessage;

class MessageSyncer
{
    private const int BATCH_SIZE = 50;

    public function __construct(
        private readonly AttachmentStorageHelper $attachmentStorage,
        private readonly MailboxRepository       $mailboxRepository,
        private readonly EntityManagerInterface  $em,
        private readonly LoggerInterface         $logger,
        private readonly MessageThreader         $messageThreader,
        private readonly MessageRepository       $messageRepository,
    ) {}

    public function syncMailbox(Mailbox $mailbox, Client $client): void
    {
        $mailboxId   = $mailbox->getId();
        $accountId   = $mailbox->getAccount()->getId();
        $lastSeenUid = $mailbox->getLastSeenUid() ?? 0;
        $uidRange    = ($lastSeenUid + 1) . ':*';

        $this->logger->info('Syncing mailbox', [
            'mailbox'     => $mailbox->getFullPath(),
            'account'     => $accountId,
            'lastSeenUid' => $lastSeenUid,
        ]);

        $folder = $client->getFolder($mailbox->getName());

        if (null === $folder) {
            $this->logger->error('Folder not found', ['mailbox' => $mailbox->getName()]);
            return;
        }

        // Load all already-synced UIDs up front so each batch can O(1)-skip them.
        // array_flip turns [123, 456, …] into [123 => 0, 456 => 1, …].
        $syncedUids = array_flip(
            $this->messageRepository->findSyncedUids($mailbox)
        );

        $synced = 0;

        $folder->messages()
            ->where('UID', $uidRange)
            ->chunked(function ($batch) use ($mailboxId, $accountId, &$synced, &$syncedUids) {
                $this->processBatch($batch, $mailboxId, $accountId, $syncedUids);
                $synced += count($batch);
                $this->em->clear();
                $this->logger->info(sprintf('Synced %d messages so far', $synced));
            }, self::BATCH_SIZE);

        $mailbox = $this->mailboxRepository->find($mailboxId);
        $mailbox->setSyncedAt(new DateTimeImmutable());
        $mailbox->setUnreadMessages($this->messageRepository->countUnseenForMailbox($mailbox));
        $mailbox->setTotalMessages($this->messageRepository->countTotalForMailbox($mailbox));
        $this->em->flush();
    }

    /**
     * @param array<int,bool> $syncedUids  passed by reference so new UIDs are
     *                                      registered within the same sync run
     *                                      (guards against duplicates inside a
     *                                      single chunked call)
     */
    private function processBatch(
        iterable $batch,
        int      $mailboxId,
        int      $accountId,
        array    &$syncedUids,
    ): void {
        $mailbox  = $this->mailboxRepository->find($mailboxId);
        $messages = [];
        $maxUid   = 0;

        // Pass 1 — build + persist Message rows (no threading yet)
        foreach ($batch as $imapMessage) {
            $uid = $imapMessage->getUid();

            if (true === isset($syncedUids[$uid])) {
                $this->logger->debug('Skipping already-synced UID', ['uid' => $uid]);
                continue;
            }

            try {
                $message = $this->buildMessage($imapMessage, $mailbox, $accountId);
                $this->em->persist($message);
                $messages[]        = $message;
                $syncedUids[$uid]  = true; // mark within this run

                if (true === ($uid > $maxUid)) {
                    $maxUid = $uid;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to build message', [
                    'uid'   => $uid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Flush so all new messages have IDs before the threader queries them
        $this->em->flush();

        // Pass 2 — assign threads now that all messages exist in DB
        foreach ($messages as $message) {
            try {
                $this->messageThreader->assignThread(
                    $message,
                    $mailbox->getAccount(),
                    $mailbox,
                );
            } catch (\Throwable $e) {
                $this->logger->error('Failed to assign thread', [
                    'messageId' => $message->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        if (true === ($maxUid > 0)) {
            $mailbox->setLastSeenUid($maxUid);
        }

        $this->em->flush();
    }

    private function buildMessage(ImapMessage $imapMessage, Mailbox $mailbox, int $accountId): Message
    {
        $message = new Message();
        $message->setMailbox($mailbox);
        $message->setImapUid($imapMessage->getUid());
        $message->setMessageId((string) $imapMessage->getMessageId());
        $message->setSubject(
            $this->decodeMimeHeader((string) $imapMessage->getSubject())
        );

        // From
        $from = $imapMessage->getFrom()->first();
        if (null !== $from) {
            $message->setFromAddress($from->mail ?? '');
            $message->setFromName(
                $this->decodeMimeHeader((string) $from->personal)
            );
        }

        // Recipients
        $message->setToAddresses($this->formatAddresses($imapMessage->getTo()));
        $message->setCcAddresses($this->formatAddresses($imapMessage->getCc()));
        $message->setBccAddresses($this->formatAddresses($imapMessage->getBcc()));

        // Dates
        $date = $imapMessage->getDate()->toDate();
        $receivedAt = DateTimeImmutable::createFromInterface($date);
        $message->setSentAt($receivedAt);
        $message->setReceivedAt($receivedAt);

        // Flags
        $flagNames = array_values($imapMessage->getFlags()->toArray());
        $message->setFlags($flagNames);

        if (
            true === in_array('Seen', $flagNames, true)
            || true === in_array('\\Seen', $flagNames, true)
        ) {
            $message->setSeenAt(new DateTimeImmutable());
        }

        // Threading headers
        $inReplyTo  = $imapMessage->getInReplyTo();
        $references = $imapMessage->getReferences();

        $message->setInReplyTo(
            $inReplyTo->exist() ? explode(' ', trim((string) $inReplyTo)) : []
        );
        $message->setReferences(
            $references->exist() ? explode(' ', trim((string) $references)) : []
        );

        // Body
        $message->setBodyText($imapMessage->getTextBody() ?? '');
        $message->setBodyHtml($imapMessage->getHTMLBody() ?? '');

        // Attachments
        $attachments = $imapMessage->getAttachments();
        $message->setHasAttachments($attachments->isNotEmpty());
        $message->setSyncedAt(new DateTimeImmutable());

        foreach ($attachments as $attachment) {
            $this->persistAttachment($attachment, $message, $accountId);
        }

        return $message;
    }

    private function persistAttachment(mixed $attachment, Message $message, int $accountId): void
    {
        $filename = $attachment->getFilename() ?? ('attachment_' . uniqid());
        $content  = $attachment->getContent();

        $storagePath = $this->attachmentStorage->store(
            $accountId,
            $message->getMailbox()->getId(),
            $message->getImapUid(),
            $filename,
            $content,
        );

        $part = new MessagePart();
        $part->setMessage($message);
        $part->setContentType($attachment->getContentType() ?? 'application/octet-stream');
        $part->setFilename($filename);
        $part->setDisposition($attachment->getDisposition() ?? 'attachment');
        $part->setSize(strlen($content));
        $part->setStoragePath($storagePath);
        $part->setIsInline(($attachment->getDisposition() ?? '') === 'inline');

        $this->em->persist($part);
    }

    private function formatAddresses(mixed $attribute): array
    {
        if (null === $attribute) {
            return [];
        }

        $result = [];
        foreach ($attribute as $address) {
            $result[] = [
                'name'    => $address->personal ?? '',
                'address' => $address->mail ?? '',
            ];
        }

        return $result;
    }

    private function decodeMimeHeader(string $value): string
    {
        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        if (false === $decoded) {
            return $value;
        }

        return $decoded;
    }
}
