<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Which content types this application is willing to hand a browser inline.
 *
 * The rule used to be `str_starts_with($contentType, 'image/')`, written in
 * three places, and `image/svg+xml` satisfies it. SVG is not a passive image
 * format: loaded as a top-level document it executes `<script>`. And
 * `X-Content-Type-Options: nosniff` is no help at all here — nothing is being
 * sniffed or mislabelled, the declared type genuinely IS `image/svg+xml`. The
 * type itself comes out of the MIME headers of an incoming mail, so it is
 * chosen by whoever sent it.
 *
 * Reachable, because MailBodySanitizer::resolveCids() rewrites EVERY `cid:`
 * occurrence in the document rather than only the ones in `img src`. An
 * attacker attaches an SVG carrying a script, writes
 * `<a href="cid:logo">Rechnung ansehen</a>` in the body, and the application
 * itself fills in the part id it could not have guessed. The templates all link
 * attachments with `?download=1`, so the ordinary interface never reaches the
 * inline path — the mail does.
 *
 * Hence an allow-list, which is the difference between "everything not yet
 * known to be dangerous" and "the formats actually wanted". Nothing on it can
 * carry script.
 */
final class InlineDisposition
{
    /**
     * Raster formats and nothing else. `image/svg+xml` is deliberately absent
     * and is the whole reason this list exists; anything not named here falls
     * back to a download, which is the safe answer for a format nobody
     * anticipated.
     */
    public const array INLINE_TYPES = [
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/apng',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/bmp',
        'image/x-ms-bmp',
        'image/tiff',
        'image/heic',
        'image/heif',
        'image/x-icon',
        'image/vnd.microsoft.icon',
    ];

    /**
     * Defence in depth on the response itself, so the decision above is not the
     * only thing standing between a stored file and the app's origin.
     *
     * `sandbox` with no tokens is the strong form: no scripts, no forms, no
     * top-level navigation, and an opaque origin — so even a document that does
     * reach the browser cannot reach the session. `default-src 'none'` stops it
     * fetching anything of its own. ImageProxyController already does exactly
     * this to its responses and is the model.
     *
     * Worth stating plainly: with this header, a future edit that widens the
     * list above is a bug rather than a vulnerability.
     */
    public const string SANDBOX_CSP = "default-src 'none'; sandbox";

    /**
     * True when a browser may be told to render this inline.
     *
     * Parameters are cut before comparing, because `image/png; charset=utf-8`
     * is a real thing to find in a mail header and would sail past an exact
     * match — which is the kind of near-miss that turns an allow-list back into
     * a deny-list.
     */
    public static function allows(?string $contentType): bool
    {
        return in_array(self::normalise($contentType), self::INLINE_TYPES, true);
    }

    /** The bare type, lowercased, with any parameters and padding removed. */
    public static function normalise(?string $contentType): string
    {
        if (null === $contentType) {
            return '';
        }

        return strtolower(trim(explode(';', $contentType)[0]));
    }
}
