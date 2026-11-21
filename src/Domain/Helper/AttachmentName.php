<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * The name to store for a webklex attachment.
 *
 * A part can carry its name in two places: `Content-Disposition: attachment;
 * filename=…` and the older `Content-Type: …; name=…`. Plenty of business
 * mailers still use only the second — The Arch Company's statements arrive as
 *
 *     Content-Type: application/octet-stream; name=TNNTST02_10561121.PDF
 *     Content-Disposition: attachment
 *
 * webklex reads `filename` from the disposition alone and, finding none there,
 * substitutes its own crc32c hash of the part ("57d5c7cb"). The real name sits
 * in `name` the whole time. Stored as the hash, the chip shows "57d5c7cb", the
 * download has no extension for the operating system to open it by, and a PDF
 * sent as octet-stream gets no preview — PdfAttachment recognises it only by
 * its `.pdf`.
 *
 * Only the name webklex substituted is replaced. A part that names nothing at
 * all keeps the hash, because MisfiledBodyDetector recognises a body webklex
 * filed as an attachment by exactly that eight-hex-character name. A message/*
 * part is left alone too: webklex fills its `name` with the subtype ("RFC822")
 * when it has none, which is no better a filename than the hash.
 */
final readonly class AttachmentName
{
    /**
     * Decoded, or null when webklex reported no filename at all.
     */
    public static function of(mixed $attachment): ?string
    {
        $filename = $attachment->getFilename();

        if (null === $filename) {
            return null;
        }

        $filename = (string) $filename;
        $hash     = (string) ($attachment->getHash() ?? '');

        if ('' !== $hash && $filename === $hash) {
            $name = self::declaredName($attachment, $hash);

            if (null !== $name) {
                return MimeHeaderHelper::decode($name);
            }
        }

        return MimeHeaderHelper::decode($filename);
    }

    private static function declaredName(mixed $attachment, string $hash): ?string
    {
        $type = strtolower(trim((string) ($attachment->getContentType() ?? '')));

        if (str_starts_with($type, 'message/')) {
            return null;
        }

        $name = trim((string) ($attachment->getName() ?? ''));

        if ('' === $name || $name === $hash) {
            return null;
        }

        return $name;
    }
}
