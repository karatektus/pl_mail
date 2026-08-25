<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Whether an attachment is a PDF this app will offer to open.
 *
 * Its own helper rather than a `'application/pdf' === $type` at the call sites,
 * for the reason InlineDisposition is one: the answer is asked in three places
 * (the chip, the preview route, the tests) and the interesting part is what
 * counts as a PDF when the sender was careless.
 *
 * Three cases, each earned:
 *
 * - The declared type may carry parameters. `application/pdf; name="x.pdf"` is
 *   ordinary in mail, and a bare string comparison against it fails — the same
 *   normalisation InlineDisposition::allows() does, and for the same reason.
 * - `application/x-pdf` predates the registered type and is still emitted.
 * - `application/octet-stream` plus a `.pdf` name. Outlook does this routinely,
 *   and refusing it means the preview button is absent on exactly the documents
 *   corporate senders attach.
 *
 * The extension is trusted ONLY under octet-stream, never on its own. A part
 * declaring `image/png` and named `invoice.pdf` is a png; believing the name
 * over the type is how a viewer gets handed something it cannot parse — and, on
 * a bad day, is the shape of a content-type confusion bug. The bytes are never
 * consulted here: this decides whether to offer a viewer, and the viewer itself
 * fails visibly on something that is not a PDF.
 */
final readonly class PdfAttachment
{
    /** The registered type and the one that predates it. */
    private const array PDF_TYPES = ['application/pdf', 'application/x-pdf'];

    /** The only declared type whose filename gets a say. */
    private const string UNKNOWN = 'application/octet-stream';

    public static function matches(?string $contentType, ?string $filename): bool
    {
        $type = self::normalise($contentType);

        if (true === in_array($type, self::PDF_TYPES, true)) {
            return true;
        }

        if (self::UNKNOWN !== $type) {
            return false;
        }

        return str_ends_with(mb_strtolower(trim((string) $filename)), '.pdf');
    }

    /**
     * The bare type, lowercased, without parameters.
     *
     * Cuts at the first `;` — `application/pdf; name="x.pdf"` is the type plus a
     * parameter, and only the type is being compared.
     */
    private static function normalise(?string $contentType): string
    {
        $type = mb_strtolower(trim((string) $contentType));
        $cut  = strpos($type, ';');

        return false === $cut ? $type : trim(substr($type, 0, $cut));
    }
}
