<?php

namespace App\Service\Imap;

use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Message;
use App\Entity\MessagePart;
use App\Entity\Mailbox;
use App\Repository\MessageRepository;
use App\Repository\MailboxRepository;
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

        $synced = 0;

        $folder->messages()
            ->where('UID', $uidRange)
            ->chunked(function ($batch) use ($mailboxId, $accountId, &$synced) {
                $this->processBatch($batch, $mailboxId, $accountId);
                $synced += count($batch);
                $this->em->clear();
                $this->logger->info(sprintf('Synced %d messages so far', $synced));
            }, self::BATCH_SIZE);

        $mailbox = $this->mailboxRepository->find($mailboxId);
        $mailbox->setSyncedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    private function processBatch($batch, int $mailboxId, int $accountId): void
    {
        $mailbox = $this->mailboxRepository->find($mailboxId);
        $maxUid  = 0;

        foreach ($batch as $imapMessage) {
            try {
                $this->persistMessage($imapMessage, $mailbox, $accountId);

                if ($imapMessage->getUid() > $maxUid) {
                    $maxUid = $imapMessage->getUid();
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to sync message', [
                    'uid'   => $imapMessage->getUid(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Update lastSeenUid so next sync only fetches newer messages
        if ($maxUid > 0) {
            $mailbox->setLastSeenUid($maxUid);
        }

        $this->em->flush();
    }

    private function persistMessage(ImapMessage $imapMessage, Mailbox $mailbox, int $accountId): void
    {
        $message = new Message();
        $message->setMailbox($mailbox);
        $message->setImapUid($imapMessage->getUid());
        $message->setMessageId((string) $imapMessage->getMessageId());
        $message->setSubject((string) $imapMessage->getSubject() ?: '(no subject)');

        // From
        $from = $imapMessage->getFrom()->first();
        if ($from !== null) {
            $message->setFromAddress($from->mail ?? '');
            $message->setFromName($from->personal ?? '');
        }

        // Recipients
        $message->setToAddresses($this->formatAddresses($imapMessage->getTo()));
        $message->setCcAddresses($this->formatAddresses($imapMessage->getCc()));
        $message->setBccAddresses($this->formatAddresses($imapMessage->getBcc()));

        // Dates
        $date = $imapMessage->getDate()->toDate();
        $message->setSentAt(\DateTimeImmutable::createFromInterface($date));
        $message->setReceivedAt(\DateTimeImmutable::createFromInterface($date));

        // Flags
        $flags     = $imapMessage->getFlags()->toArray();
        $flagNames = array_values($flags);
        $message->setFlags($flagNames);

        if (in_array('Seen', $flagNames, true) || in_array('\\Seen', $flagNames, true)) {
            $message->setSeenAt(new \DateTimeImmutable());
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

        $message->setSyncedAt(new \DateTimeImmutable());

        $this->messageThreader->assignThread($message, $mailbox->getAccount(), $mailbox);
        $this->em->persist($message);

        foreach ($attachments as $attachment) {
            $this->persistAttachment($attachment, $message, $accountId);
        }
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
}
