<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Whether an attachment is a picture or a video this app will offer to show
 * (#295), and for a video, the type to play it under.
 *
 * PdfAttachment's sibling, and built the same way: the answer is decided from
 * the declared type, and the filename only gets a say when the sender declared
 * `application/octet-stream` — Outlook's label for everything. A part that
 * calls itself `text/html` is never re-read as a video because of its name.
 *
 * IMAGES are the InlineDisposition list minus the formats most browsers cannot
 * draw. That list already decides what serve() hands a browser inline, so the
 * preview is just an `<img>` on the ordinary attachment URL and adds no new
 * way for bytes to reach the page. TIFF and HEIC are left out not for safety
 * but for honesty: Chrome and Firefox show neither, and a dialog that opens on
 * a broken picture is worse than the download it replaced. SVG is not here for
 * the reason InlineDisposition gives.
 *
 * VIDEOS are new to the inline path, and get their own route rather than a
 * wider InlineDisposition — that list is also what the signed `cid:` route and
 * the sniffer answer to, and none of them has any business with video. The
 * formats are the four a browser `<video>` plays natively. None of them can
 * carry script; loaded as a top-level document each opens the browser's own
 * player, under the same sandbox CSP every attachment response carries.
 */
final readonly class MediaAttachment
{
    public const string IMAGE = 'image';
    public const string VIDEO = 'video';

    /** InlineDisposition::INLINE_TYPES without TIFF and HEIC/HEIF. */
    private const array IMAGE_TYPES = [
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/apng',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/bmp',
        'image/x-ms-bmp',
        'image/x-icon',
        'image/vnd.microsoft.icon',
    ];

    private const array VIDEO_TYPES = [
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/quicktime',
    ];

    /**
     * Trusted only under octet-stream. For an image the name merely decides
     * whether to OFFER a preview: serve() still sniffs the bytes of an
     * unlabelled part and sends a download for anything that is not a raster
     * image, which the dialog reports as "could not be shown".
     */
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico'];

    /** Extension → the type the video route serves it under. */
    private const array VIDEO_EXTENSIONS = [
        'mp4'  => 'video/mp4',
        'm4v'  => 'video/mp4',
        'webm' => 'video/webm',
        'ogv'  => 'video/ogg',
        'mov'  => 'video/quicktime',
    ];

    private const string UNKNOWN = 'application/octet-stream';

    /** IMAGE, VIDEO, or null when there is nothing to show. */
    public static function kindOf(?string $contentType, ?string $filename): ?string
    {
        $type = InlineDisposition::normalise($contentType);

        if (true === in_array($type, self::IMAGE_TYPES, true)) {
            return self::IMAGE;
        }

        if (true === in_array($type, self::VIDEO_TYPES, true)) {
            return self::VIDEO;
        }

        if (self::UNKNOWN !== $type) {
            return null;
        }

        $extension = self::extension($filename);

        if (true === in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return self::IMAGE;
        }

        return true === array_key_exists($extension, self::VIDEO_EXTENSIONS) ? self::VIDEO : null;
    }

    /**
     * The type to serve a video part under, or null for anything that is not
     * one. Always one of VIDEO_TYPES — never the sender's string as written,
     * so parameters and case cannot travel into the response header.
     */
    public static function videoType(?string $contentType, ?string $filename): ?string
    {
        if (self::VIDEO !== self::kindOf($contentType, $filename)) {
            return null;
        }

        $type = InlineDisposition::normalise($contentType);

        return true === in_array($type, self::VIDEO_TYPES, true)
            ? $type
            : self::VIDEO_EXTENSIONS[self::extension($filename)];
    }

    private static function extension(?string $filename): string
    {
        return mb_strtolower(pathinfo(trim((string) $filename), PATHINFO_EXTENSION));
    }
}
