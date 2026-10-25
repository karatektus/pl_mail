<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use Psr\Log\LoggerInterface;

/**
 * The files a forward carries along: the original's attachments, copied onto
 * the draft so the window opens with them already attached.
 *
 * A forward passes a mail on, and the file is usually the point of it — the
 * invoice, the ticket, the contract. The quote alone is the least of what the
 * recipient needs. Copied rather than referenced, because a draft owns its
 * files: it can drop one, and deleting the draft deletes its bytes, neither of
 * which may reach back into the message it was forwarded from.
 *
 * Real attachments only. An inline image is part of the body, and the quote
 * already carries it; attaching it again would put a second, visible copy of a
 * picture the reader sees in the text.
 *
 * Nor the wrapping of a signed or encrypted mail. A signature part signs the
 * original, not the forward, and an encrypted mail's parts are ciphertext only
 * this mailbox's key opens — its files are inside it, and the browser that
 * decrypts the quote does not hand them back to the server.
 *
 * A reply does not come through here. Replies have never quoted the original's
 * files, and nobody sends a document back to the person who sent it.
 */
final readonly class ForwardedAttachments
{
    private const array SIGNATURE_TYPES = [
        'application/pgp-signature',
        'application/pkcs7-signature',
        'application/x-pkcs7-signature',
    ];

    public function __construct(
        private AttachmentResolver     $resolver,
        private DraftAttachmentService $attachments,
        private LoggerInterface        $logger,
    ) {
    }

    /** Whether forwarding this message has anything to copy. */
    public function has(Message $original): bool
    {
        return [] !== $this->attachable($original);
    }

    /**
     * Copy the original's attachments onto a draft that is already saved.
     *
     * Saved first because the bytes are bucketed by message id, and a draft
     * the forward window has merely built does not have one yet.
     *
     * A file that cannot be copied is skipped and logged rather than failing
     * the forward: the window shows what was attached, so a missing chip is
     * visible, and a forward that refuses to open over one broken attachment
     * is worse than one that opens without it.
     *
     * @return int how many files were attached
     */
    public function copy(Message $original, Message $draft): int
    {
        $copied = 0;

        foreach ($this->attachable($original) as $part) {
            // The same ceiling an upload gets: the sender's mail server limits
            // what it relays, and a file the original arrived with can be
            // larger than anything the compose window would have accepted.
            if (null !== $part->size && $part->size > DraftAttachmentService::MAX_BYTES) {
                $this->logger->warning('ForwardedAttachments: attachment over the compose limit, not copied', [
                    'partId' => $part->id,
                    'size'   => $part->size,
                ]);

                continue;
            }

            try {
                $contents = file_get_contents($this->resolver->absolutePathFor($part));
            } catch (\RuntimeException $failure) {
                $contents = false;

                $this->logger->warning('ForwardedAttachments: attachment could not be fetched, not copied', [
                    'partId' => $part->id,
                    'error'  => $failure->getMessage(),
                ]);
            }

            if (false === $contents || strlen($contents) > DraftAttachmentService::MAX_BYTES) {
                continue;
            }

            $this->attachments->attachBytes(
                $draft,
                (string) ($part->filename ?: 'attachment'),
                // The type the sender declared, as it was stored. Unlike an
                // upload there is no browser to distrust here, but the value
                // still goes out as a Content-Type, so nothing is added to it.
                (string) ($part->contentType ?: 'application/octet-stream'),
                $contents,
            );

            ++$copied;
        }

        return $copied;
    }

    /** @return list<MessagePart> */
    private function attachable(Message $original): array
    {
        if (true === $original->crypto?->isEncrypted()) {
            return [];
        }

        $parts = [];

        foreach ($original->messageParts as $part) {
            if (true === (bool) $part->isInline || null === $part->storagePath || '' === $part->storagePath) {
                continue;
            }

            if (true === self::isSignature($part)) {
                continue;
            }

            $parts[] = $part;
        }

        return $parts;
    }

    /**
     * A detached signature, PGP or S/MIME. Not Exchange's smime.p7m on a signed
     * mail: that one holds the whole signed body, files included.
     */
    private static function isSignature(MessagePart $part): bool
    {
        $type = strtolower(trim(explode(';', (string) $part->contentType)[0]));
        $name = strtolower((string) $part->filename);

        return in_array($type, self::SIGNATURE_TYPES, true)
            || in_array($name, ['signature.asc', 'smime.p7s'], true);
    }
}
