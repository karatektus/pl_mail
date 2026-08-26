<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\MessagePart;
use Psr\Log\LoggerInterface;

/**
 * Small, cached previews for image attachments.
 *
 * Deliberately narrow. "Cheap" is the whole point of showing these at all, so
 * the gate is conservative on every axis and anything that fails it falls back
 * to the paperclip icon rather than to a slower path:
 *
 *   - four raster formats GD reads natively. No SVG (it is XML, and rendering
 *     untrusted XML from a stranger's mail is not a preview feature), no PDF,
 *     no video — those need a renderer this app does not ship.
 *   - a byte ceiling, because decoding is linear in file size.
 *   - a pixel ceiling checked from the header *before* decoding, because file
 *     size is not: a few KB of PNG can expand to gigabytes of bitmap.
 *
 * Thumbnails are written next to nothing — a flat cache directory keyed by part
 * id — and generated once. The part's bytes are immutable, so there is no
 * invalidation to get wrong; deleting the directory is a full, safe reset.
 */
final readonly class AttachmentThumbnailer
{
    /** GD reads these without help. */
    private const array TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const int MAX_BYTES = 12 * 1024 * 1024;

    /** Above this, decoding costs more memory than a thumbnail is worth. */
    private const int MAX_PIXELS = 40_000_000;

    private const int EDGE = 160;

    public function __construct(
        private AttachmentResolver $attachments,
        private LoggerInterface $logger,
        private string $projectDir,
    ) {
    }

    /**
     * Whether a preview is worth attempting. Cheap enough for a template to
     * call once per attachment: it reads the part's own columns and nothing
     * else, and in particular never touches the file or the provider.
     */
    public function isPreviewable(MessagePart $part): bool
    {
        if (false === function_exists('imagecreatetruecolor')) {
            return false;
        }

        $size = $part->size;

        if (null !== $size && $size > self::MAX_BYTES) {
            return false;
        }

        return in_array(strtolower((string) $part->contentType), self::TYPES, true);
    }

    /**
     * Absolute path to the cached thumbnail, generating it on first call.
     * Null when the part is not previewable or the image will not decode —
     * callers fall back to an icon.
     */
    public function thumbnailPath(MessagePart $part): ?string
    {
        if (false === $this->isPreviewable($part) || null === $part->id) {
            return null;
        }

        $cachePath = sprintf('%s/var/attachments/thumbs/%d.webp', $this->projectDir, $part->id);

        if (true === is_file($cachePath)) {
            return $cachePath;
        }

        try {
            $source = $this->attachments->absolutePathFor($part);
        } catch (\Throwable $exception) {
            // A provider fetch can fail for reasons that have nothing to do
            // with previews; the download link surfaces the real error.
            $this->logger->debug('AttachmentThumbnailer: source unavailable', [
                'partId'    => $part->id,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return null;
        }

        return $this->generate($source, $cachePath, (int) $part->id);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function generate(string $source, string $cachePath, int $partId): ?string
    {
        if (false === is_file($source) || filesize($source) > self::MAX_BYTES) {
            return null;
        }

        // Header only — this is what keeps a decompression bomb from being
        // decoded before anyone notices how big it is.
        $info = @getimagesize($source);

        if (false === $info) {
            return null;
        }

        [$width, $height] = $info;

        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            return null;
        }

        $image = @imagecreatefromstring((string) file_get_contents($source));

        if (false === $image) {
            return null;
        }

        try {
            $scale  = min(self::EDGE / $width, self::EDGE / $height, 1);
            $target = imagescale($image, max(1, (int) round($width * $scale)));

            if (false === $target) {
                return null;
            }

            $directory = dirname($cachePath);

            if (false === is_dir($directory) && false === @mkdir($directory, 0755, true) && false === is_dir($directory)) {
                return null;
            }

            try {
                // Write-then-rename: two threads rendering the same thread body
                // would otherwise race and one could serve a half-written file.
                $temporary = $cachePath . '.' . bin2hex(random_bytes(4));

                if (false === imagewebp($target, $temporary, 80)) {
                    @unlink($temporary);

                    return null;
                }

                rename($temporary, $cachePath);
            } finally {
                imagedestroy($target);
            }
        } finally {
            imagedestroy($image);
        }

        $this->logger->debug('AttachmentThumbnailer: generated', ['partId' => $partId]);

        return $cachePath;
    }
}
