<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Account;
use App\Entity\MessagePart;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves a MessagePart to a real file on disk, materialising provider-hosted
 * attachments on first access.
 *
 * Two lazy schemes are supported:
 *   gmail://{attachmentId}    → Gmail messages.attachments.get
 *   msgraph://{attachmentId}  → Graph /messages/{id}/attachments/{id}/$value
 *
 * NOTE — this file also fixes a live bug. The previous implementation read
 * `$message->getMailbox()->getAccount()`, which worked when Gmail messages
 * still had Mailbox rows. Under the label architecture API-synced messages
 * have no mailbox, so the first attachment click on a Gmail message fatals on
 * a null mailbox. The account now comes from the message directly, and the
 * storage key falls back to 0 where there is no mailbox to key on.
 */
final readonly class AttachmentResolver
{
    private const string GMAIL_SCHEME = 'gmail://';
    private const string GRAPH_SCHEME = 'msgraph://';

    public function __construct(
        private AttachmentStorageHelper $attachmentStorage,
        private GmailApiClient          $gmailApiClient,
        private GraphApiClient          $graphApiClient,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
    ) {}

    /**
     * Absolute filesystem path to the part's content. Unmaterialised provider
     * attachments are downloaded and cached first.
     */
    public function absolutePathFor(MessagePart $part): string
    {
        $storagePath = (string) $part->getStoragePath();

        if (true === str_starts_with($storagePath, self::GMAIL_SCHEME)) {
            return $this->materialise($part, $storagePath, self::GMAIL_SCHEME);
        }

        if (true === str_starts_with($storagePath, self::GRAPH_SCHEME)) {
            return $this->materialise($part, $storagePath, self::GRAPH_SCHEME);
        }

        return $this->attachmentStorage->getAbsolutePath($storagePath);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function materialise(MessagePart $part, string $storagePath, string $scheme): string
    {
        $attachmentId = substr($storagePath, strlen($scheme));
        $message      = $part->getMessage();
        $account      = $message->getAccount();

        if ('' === $attachmentId || null === $account) {
            throw new \RuntimeException(sprintf(
                'Cannot materialise attachment for part %d: missing account or attachmentId.',
                (int) $part->getId(),
            ));
        }

        $remoteMessageId = self::GMAIL_SCHEME === $scheme
            ? $message->getGmailId()
            : $message->getGraphId();

        if (null === $remoteMessageId || '' === $remoteMessageId) {
            throw new \RuntimeException(sprintf(
                'Cannot materialise attachment for part %d: message has no provider id.',
                (int) $part->getId(),
            ));
        }

        $content = self::GMAIL_SCHEME === $scheme
            ? $this->gmailApiClient->getAttachment($account, $remoteMessageId, $attachmentId)
            : $this->graphApiClient->getAttachmentContent($account, $remoteMessageId, $attachmentId);

        $relativePath = $this->attachmentStorage->store(
            $account->getId(),
            $this->storageBucket($part),
            abs(crc32($remoteMessageId)),
            (string) $part->getFilename(),
            $content,
        );

        $part->setStoragePath($relativePath);
        $this->em->flush();

        $this->logger->info('AttachmentResolver: materialised provider attachment', [
            'partId' => $part->getId(),
            'scheme' => $scheme,
        ]);

        return $this->attachmentStorage->getAbsolutePath($relativePath);
    }

    /**
     * API-synced messages have no Mailbox, so there is no mailbox id to bucket
     * storage under. Zero is a stable sentinel for "provider-synced".
     */
    private function storageBucket(MessagePart $part): int
    {
        $mailbox = $part->getMessage()->getMailbox();

        if (null === $mailbox) {
            return 0;
        }

        return (int) $mailbox->getId();
    }
}
