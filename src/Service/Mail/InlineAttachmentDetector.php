<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Decides whether a MIME part is an inline body asset or a real attachment.
 *
 * A Content-ID alone means nothing: Gmail's web composer stamps
 * "Content-ID: <f_xxxx>" on every attachment it uploads. Treating "has a cid"
 * as "is inline" therefore hides ordinary attachments from the UI.
 *
 * The body is the authority: a part is inline when, and only when, the HTML
 * references its cid. Nothing else shows an inline part, so an inline part the
 * body never references would be stored and then never seen anywhere.
 *
 * Content-Disposition cannot decide it either. Apple Mail sends every file it
 * attaches as "Content-Disposition: inline" with no Content-ID, between the
 * text parts of a multipart/mixed; Gmail, Apple Mail and Thunderbird all list
 * those as attachments. Trusting the disposition hid them, with no paperclip
 * and no chip (#753).
 */
final class InlineAttachmentDetector
{
    public function isInline(?string $contentId, ?string $bodyHtml): bool
    {
        return $this->isReferencedBy($this->normalizeContentId($contentId), (string) $bodyHtml);
    }

    public function normalizeContentId(?string $contentId): string
    {
        return trim((string) $contentId, "<> \t\r\n");
    }

    /**
     * True when the HTML body embeds this part via a cid: URL.
     */
    private function isReferencedBy(string $contentId, string $bodyHtml): bool
    {
        if ('' === $contentId || '' === $bodyHtml) {
            return false;
        }

        return 1 === preg_match(
            '/cid:\s*' . preg_quote($contentId, '/') . '\b/i',
            $bodyHtml,
        );
    }
}
