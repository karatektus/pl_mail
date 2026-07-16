<?php

namespace App\Service\Imap;

use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Message;
use App\Entity\MessagePart;
use App\Entity\Mailbox;
use App\Repository\MessageRepository;
use App\Repository\MailboxRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message as ImapMessage;

class MessageSyncer
{
    private const BATCH_SIZE = 50;

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
            'mailbox' => $mailbox->getFullPath(),
            'account' => $accountId,
        ]);

        $folder = $client->getFolder($mailbox->getName());

        if ($folder === null) {
            $this->logger->error('Folder not found', ['mailbox' => $mailbox->getName()]);
            return;
        }
        $syncedUids = array_flip($this->messageRepository->findSyncedUids($mailbox) ?? []);

        $synced = 0;

        $sinceDate = $mailbox->getSyncedAt() ?? new DateTimeImmutable('-30 days');

        $folder->messages()
            ->since($sinceDate)
            ->chunked(function ($batch) use ($mailboxId, $accountId, &$synced, &$syncedUids) {
                $this->processBatch($batch, $mailboxId, $accountId, $syncedUids);
                $synced += count($batch);
                $this->em->clear();
                $this->logger->info(sprintf('Processed %d messages so far', $synced));
            }, self::BATCH_SIZE);

        $mailbox = $this->mailboxRepository->find($mailboxId);
        $mailbox->setSyncedAt(new DateTimeImmutable());
        $mailbox->setUnreadMessages($this->messageRepository->countUnseenForMailbox($mailbox));
        $mailbox->setTotalMessages($this->messageRepository->countTotalForMailbox($mailbox));
        $this->em->flush();
    }

    private function processBatch($batch, int $mailboxId, int $accountId, array &$syncedUids): void
    {
        $mailbox  = $this->mailboxRepository->find($mailboxId);
        $messages = [];
        $maxUid   = 0;

        // Pass 1 — persist all messages without threading
        foreach ($batch as $imapMessage) {
            $uid = $imapMessage->getUid();

            // Skip already-synced UIDs — critical for date-based fetching.
            if (isset($syncedUids[$uid])) {
                continue;
            }

            try {
                $message = $this->buildMessage($imapMessage, $mailbox, $accountId);
                $this->em->persist($message);
                $messages[] = $message;

                if ($imapMessage->getUid() > $maxUid) {
                    $maxUid = $imapMessage->getUid();
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to build message', [
                    'uid'   => $imapMessage->getUid(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Flush so all messages are now queryable by the threader
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

        if ($maxUid > 0) {
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
        $message->setSubject($this->decodeMimeHeader((string) $imapMessage->getSubject()));


        // From
        $from = $imapMessage->getFrom()->first();
        if ($from !== null) {
            $message->setFromAddress($from->mail ?? '');
            $message->setFromName($this->decodeMimeHeader((string) $imapMessage->getFrom()->first()->personal));
        }

        // Recipients
        $message->setToAddresses($this->formatAddresses($imapMessage->getTo()));
        $message->setCcAddresses($this->formatAddresses($imapMessage->getCc()));
        $message->setBccAddresses($this->formatAddresses($imapMessage->getBcc()));

        // Dates
        $date = $imapMessage->getDate()->toDate();
        $message->setSentAt(DateTimeImmutable::createFromInterface($date));
        $message->setReceivedAt(DateTimeImmutable::createFromInterface($date));

        // Flags
        $flags     = $imapMessage->getFlags()->toArray();
        $flagNames = array_values($flags);
        $message->setFlags($flagNames);

        if (in_array('Seen', $flagNames, true) || in_array('\\Seen', $flagNames, true)) {
            $message->setSeenAt(new DateTimeImmutable());
        }

        // Threading headers
        $inReplyTo  = $imapMessage->getInReplyTo();
        $references = $imapMessage->getReferences();

        $message->setInReplyTo(
            $inReplyTo->exist() ? explode(' ', (string) $inReplyTo) : []
        );
        $message->setReferences(
            $references->exist() ? explode(' ', (string) $references) : []
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

    private function persistAttachment($attachment, Message $message, int $accountId): void
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

    private function formatAddresses($attribute): array
    {
        if ($attribute === null) {
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

        if ($decoded === false) {
            return $value;
        }

        return $decoded;
    }
}
