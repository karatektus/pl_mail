<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\MimeHeaderHelper;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stores the attachments of a MIME message parsed here (webklex) as parts of
 * a message that already has its id — for a message a client handed over
 * whole rather than one the sync fetched: the decrypted copy of an encrypted
 * message (App\Service\Crypto\DecryptedMail) and a message brought in by
 * JMAP Email/import (App\Jmap\Mail\EmailImporter).
 *
 * The same decisions MessageSyncer::persistAttachment() makes — the name
 * decoded once for disk and row, a Content-ID that counts only when the body
 * refers to it — but keyed by the message's id rather than an IMAP uid,
 * which these messages do not have, as a draft's uploads are.
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

    public function write(Message $message, mixed $attachment): MessagePart
    {
        $raw      = $attachment->getFilename();
        $filename = null === $raw ? 'attachment_' . uniqid() : MimeHeaderHelper::decode($raw);
        $content  = (string) $attachment->getContent();

        $part = new MessagePart();
        $part->message     = $message;
        $part->contentType = $attachment->getContentType() ?? 'application/octet-stream';
        $part->filename    = $filename;
        $part->size        = strlen($content);
        $part->storagePath = $this->storage->store(
            (int) $message->account->id,
            (int) ($message->mailbox->id ?? 0),
            (int) $message->id,
            $filename,
            $content,
        );

        $contentId         = $this->inline->normalizeContentId((string) $attachment->getId());
        $isInline          = $this->inline->isInline($attachment->getDisposition(), $contentId, $message->bodyHtml);
        $part->contentId   = '' !== $contentId ? $contentId : null;
        $part->disposition = $isInline ? 'inline' : 'attachment';
        $part->isInline    = $isInline;

        $message->addMessagePart($part);
        $this->entityManager->persist($part);

        return $part;
    }
}
