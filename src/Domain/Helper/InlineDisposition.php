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

    /**
     * The Content-Type to SERVE a stored part under: its own type when it may
     * render inline, application/octet-stream for everything else.
     *
     * The stored type is whatever the sender wrote in the MIME headers, and
     * nosniff only protects a response whose label is harmless. Served as
     * `text/javascript`, an attachment on our own origin satisfied
     * `script-src 'self'` — the page CSP's one allowance — and any markup that
     * reached the app's document could load it as a script. Disposition and
     * the sandbox CSP do nothing for a subresource; only the label does. A
     * download needs no type at all: the browser and the OS go by filename.
     */
    public static function servedType(?string $contentType): string
    {
        return true === self::allows($contentType)
            ? self::normalise($contentType)
            : 'application/octet-stream';
    }

    /**
     * A filename that can go into a Content-Disposition header.
     *
     * Symfony's makeDisposition() throws on `/` and `\` — a 500 for any mail
     * whose attachment name carries a path, which senders do — and control
     * characters have no business in a header. Everything else, UTF-8
     * included, is kept: BinaryFileResponse/makeDisposition write it as
     * `filename*` and derive the ASCII fallback themselves.
     */
    public static function headerFilename(?string $filename, string $default = 'attachment'): string
    {
        $clean = str_replace(['/', '\\'], '_', (string) $filename);
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $clean));

        return '' === $clean ? $default : $clean;
    }

    /**
     * Did the sender decline to say what this is?
     *
     * S3 hands back `application/octet-stream` for any object uploaded without
     * a content type, and a great deal of the web's images live in exactly
     * that state. Amazon's return-label QR codes are one: a real 8KB PNG,
     * served unlabelled, refused by the image proxy for years while the reader
     * saw a blank space where their barcode should be.
     *
     * Mail does the same to inline parts: `application/octet-stream` is common,
     * and so is `image/octet-stream`, which is not a registered type at all —
     * Vaultwarden's notification mails put it on their logo. Neither is on the
     * list above, so those images were served as downloads and an `<img>`
     * could not show them.
     *
     * Treated as "unknown", not as "not an image" — the difference between the
     * two is the whole bug. For these labels, and only these, the bytes decide;
     * see sniff().
     */
    public static function isUnlabelled(?string $contentType): bool
    {
        return in_array(self::normalise($contentType), [
            '',
            'application/octet-stream',
            'binary/octet-stream',
            'image/octet-stream',
        ], true);
    }

    /**
     * What the bytes actually are, when that is a type on the list above;
     * null for anything else.
     *
     * This is not trusting the sender — the declared type is discarded and the
     * content is identified from its own magic bytes, then held to the same
     * allow-list, which excludes SVG. SVG matters here: it is the one image
     * format that can carry script, and serving one inline would be serving
     * script from our own origin. So an unlabelled part can only ever come out
     * as one of a fixed set of raster types, whatever the sender wrote.
     * The start of the content is enough; there is no need to pass a whole
     * attachment.
     */
    public static function sniff(string $bytes): ?string
    {
        if ('' === $bytes) {
            return null;
        }

        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (false === $sniffed) {
            return null;
        }

        $sniffed = self::normalise($sniffed);

        return true === self::allows($sniffed) ? $sniffed : null;
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
