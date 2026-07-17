<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\MessagePart;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class AttachmentResolver
{
    private const string GMAIL_SCHEME = 'gmail://';

    public function __construct(
        private AttachmentStorageHelper $attachmentStorage,
        private GmailApiClient          $apiClient,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
    ) {}

    /**
     * Absolute filesystem path to the part's content. If it's an unmaterialised
     * Gmail attachment, it's downloaded and cached first.
     */
    public function absolutePathFor(MessagePart $part): string
    {
        $storagePath = (string) $part->getStoragePath();

        if (false === str_starts_with($storagePath, self::GMAIL_SCHEME)) {
            return $this->attachmentStorage->getAbsolutePath($storagePath);
        }

        return $this->materialiseGmail($part, $storagePath);
    }

    private function materialiseGmail(MessagePart $part, string $storagePath): string
    {
        $attachmentId = substr($storagePath, strlen(self::GMAIL_SCHEME));
        $message      = $part->getMessage();
        $gmailId      = $message->getGmailId();

        if (null === $gmailId || '' === $attachmentId) {
            throw new \RuntimeException(sprintf(
                'Cannot materialise Gmail attachment for part %d: missing gmailId or attachmentId.',
                (int) $part->getId(),
            ));
        }

        $mailbox = $message->getMailbox();
        $account = $mailbox->getAccount();

        $content = $this->apiClient->getAttachment($account, $gmailId, $attachmentId);

        $relativePath = $this->attachmentStorage->store(
            $account->getId(),
            $mailbox->getId(),
            abs(crc32($gmailId)),
            (string) $part->getFilename(),
            $content,
        );

        $part->setStoragePath($relativePath);
        $this->em->flush();

        $this->logger->info('AttachmentResolver: materialised Gmail attachment', [
            'partId'  => $part->getId(),
            'gmailId' => $gmailId,
        ]);

        return $this->attachmentStorage->getAbsolutePath($relativePath);
    }
}
