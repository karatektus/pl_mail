<?php

declare(strict_types=1);

namespace App\Service\Mail\SenderPicture;

use App\Domain\DTO\Mail\FetchedPicture;
use App\Domain\Helper\SvgSanitizer;

/**
 * Turns what a lookup fetched into what plMail stores and serves (#639).
 *
 * **Rasters are re-encoded, never kept as they came.** Decoded with GD and
 * written out again as a 128-pixel PNG, which does three things at once: it
 * proves the bytes are an image and not something labelled as one, it drops
 * whatever else rode along (metadata, a polyglot's second payload), and it
 * makes every stored picture a few kilobytes however large the original was.
 * Fitted inside the square on a transparent ground rather than cropped:
 * Gravatars are square already, and a logo cropped to a circle loses its
 * edges.
 *
 * **SVGs are sanitised, never rasterised.** The image has GD and no SVG
 * renderer, and BIMI logos are SVG by definition. SvgSanitizer — the one the
 * custom logo upload uses — cuts the file down to drawing elements, and the
 * route serves it under a policy that runs nothing (SenderPictureController).
 * An `<img>` never runs an SVG's scripts in the first place; the policy is for
 * whoever opens the URL on its own.
 */
final readonly class SenderPictureImage
{
    /** The stored edge, in pixels: a 36-pixel circle at three times density, with room. */
    public const int EDGE = 128;

    /**
     * Below this a picture is a blur scaled up, and initials are clearer. Most
     * favicons are 16 or 32; a Gravatar or an apple-touch-icon is far larger.
     */
    public const int MIN_EDGE = 48;

    /**
     * Above this GD is not asked to decode at all. A 20,000-pixel square PNG
     * is a few kilobytes on the wire and over a gigabyte once decoded — the
     * decompression bomb a lookup must not be able to hand the worker.
     */
    private const int MAX_DECODE_EDGE = 4096;

    private const array RASTER_TYPES = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    public function __construct(
        private SvgSanitizer $svgSanitizer,
    ) {}

    /** Whether normalize() would keep this — for a lookup choosing among several icons. */
    public function isUsable(string $body, string $contentType): bool
    {
        if (true === self::isSvg($body, $contentType)) {
            return null !== $this->svgSanitizer->sanitize($body);
        }

        return null !== self::rasterSize($body);
    }

    /**
     * The stored form, or null when the bytes are not a picture worth keeping.
     *
     * @return array{bytes: string, extension: 'png'|'svg'}|null
     */
    public function normalize(FetchedPicture $picture): ?array
    {
        if (true === self::isSvg($picture->body, $picture->contentType)) {
            $clean = $this->svgSanitizer->sanitize($picture->body);

            return null === $clean ? null : ['bytes' => $clean, 'extension' => 'svg'];
        }

        $size = self::rasterSize($picture->body);

        if (null === $size) {
            return null;
        }

        $source = @imagecreatefromstring($picture->body);

        if (false === $source) {
            return null;
        }

        [$width, $height] = $size;

        $scale  = min(self::EDGE / $width, self::EDGE / $height);
        $scaled = [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];

        $canvas = imagecreatetruecolor(self::EDGE, self::EDGE);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        imagecopyresampled(
            $canvas,
            $source,
            intdiv(self::EDGE - $scaled[0], 2),
            intdiv(self::EDGE - $scaled[1], 2),
            0,
            0,
            $scaled[0],
            $scaled[1],
            $width,
            $height,
        );

        ob_start();
        imagepng($canvas, null, 9);
        $bytes = (string) ob_get_clean();

        return '' === $bytes ? null : ['bytes' => $bytes, 'extension' => 'png'];
    }

    /**
     * An SVG is what its bytes say, not its header — a logo served as
     * text/plain is still a logo, and an image/svg+xml that is a PNG is not.
     */
    private static function isSvg(string $body, string $contentType): bool
    {
        if ('image/svg+xml' === $contentType) {
            return true;
        }

        $start = ltrim(substr($body, 0, 512));

        return true === str_starts_with($start, '<svg') || (true === str_starts_with($start, '<?xml') && true === str_contains($start, '<svg'));
    }

    /**
     * Width and height of a raster GD may decode, or null when it is not one,
     * is too small to be worth showing, or too large to decode safely.
     *
     * @return array{int, int}|null
     */
    private static function rasterSize(string $body): ?array
    {
        $info = @getimagesizefromstring($body);

        if (false === $info || false === in_array($info[2], self::RASTER_TYPES, true)) {
            return null;
        }

        $width  = (int) $info[0];
        $height = (int) $info[1];

        if (min($width, $height) < self::MIN_EDGE || max($width, $height) > self::MAX_DECODE_EDGE) {
            return null;
        }

        return [$width, $height];
    }
}
