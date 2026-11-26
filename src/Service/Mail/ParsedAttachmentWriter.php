<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\ParsedPart;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stores the attachments of a MIME message parsed here (MessageParser) as
 * parts of a message that already has its id — for a message a client handed
 * over whole rather than one the sync fetched: the decrypted copy of an
 * encrypted message (App\Service\Crypto\DecryptedMail) and a message brought
 * in by JMAP Email/import (App\Jmap\Mail\EmailImporter).
 *
 * The same decisions MessageSyncer::persistAttachment() makes — the name the
 * parser decoded, a Content-ID that counts only when the body refers to it —
 * but keyed by the message's id rather than an IMAP uid, which these messages
 * do not have, as a draft's uploads are.
 *
 * Persists; the caller flushes.
 */
final readonly class ParsedAttachmentWriter
{
    public function __construct(
        private EntityManagerInterface   $entityManager,
        private AttachmentStorageHelper  $storage,
        private InlineAttachmentDetector $inline,
    ) {
    }

    public function write(Message $message, ParsedPart $attachment): MessagePart
    {
        $filename = $attachment->filename ?? AttachmentStorageHelper::fallbackName($attachment->contentType);

        $part = new MessagePart();
        $part->message     = $message;
        $part->contentType = '' !== $attachment->contentType ? $attachment->contentType : 'application/octet-stream';
        $part->filename    = $filename;
        $part->size        = strlen($attachment->content);
        $part->storagePath = $this->storage->store(
            (int) $message->account->id,
            (int) ($message->mailbox->id ?? 0),
            (int) $message->id,
            $filename,
            $attachment->content,
        );

        $contentId         = $this->inline->normalizeContentId($attachment->contentId);
        $isInline          = $this->inline->isInline($contentId, $message->bodyHtml);
        $part->contentId   = '' !== $contentId ? $contentId : null;
        $part->disposition = $isInline ? 'inline' : 'attachment';
        $part->isInline    = $isInline;

        $message->addMessagePart($part);
        $this->entityManager->persist($part);

        return $part;
    }
}
