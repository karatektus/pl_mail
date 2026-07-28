<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Decides whether a MIME part is an inline body asset or a real attachment.
 *
 * A Content-ID alone means nothing: Gmail's web composer stamps
 * "Content-ID: <f_xxxx>" on every attachment it uploads, and Webklex's
 * Attachment::getId() falls back to a content hash when the part carries no
 * Content-ID at all. Treating "has a cid" as "is inline" therefore hides
 * ordinary attachments from the UI.
 *
 * The body is the authority: a part is inline when the HTML actually
 * references its cid. Content-Disposition only decides the cases the body
 * cannot (no HTML part, or a cid the body never mentions).
 */
final class InlineAttachmentDetector
{
    public function isInline(
        ?string $disposition,
        ?string $contentId,
        ?string $bodyHtml,
    ): bool {
        $contentId = $this->normalizeContentId($contentId);

        if (true === $this->isReferencedBy($contentId, (string) $bodyHtml)) {
            return true;
        }

        $disposition = strtolower(trim((string) $disposition));

        if (true === str_contains($disposition, 'attachment')) {
            return false;
        }

        return str_contains($disposition, 'inline');
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
