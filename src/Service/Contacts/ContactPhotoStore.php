<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores contact photos under %kernel.project_dir%/var/contacts/{userId}/.
 * JPEG/PNG/WebP only, 5 MB max, downscaled so the longest side is <= 512 px
 * (GD). Filenames are random hex; the stored relative path (e.g.
 * 'contacts/42/abcdef.jpg') goes into Contact::$photoPath.
 */
final class ContactPhotoStore
{
    public const int MAX_BYTES = 5 * 1024 * 1024;
    public const int MAX_DIMENSION = 512;

    private const array ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function validateUpload(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Photo exceeds the 5 MB limit');
        }
        $this->assertMime($file->getMimeType());
    }

    private function assertMime(?string $mime): void
    {
        if (null === $mime || !isset(self::ALLOWED_MIME[$mime])) {
            throw new \InvalidArgumentException('Unsupported photo type (allowed: jpg, png, webp)');
        }
    }

    public function store(UploadedFile $file, int $userId): string
    {
        $this->validateUpload($file);

        return $this->storeBytes((string) $file->getContent(), (string) $file->getMimeType(), $userId);
    }

    public function storeBytes(string $bytes, string $mime, int $userId): string
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Photo exceeds the 5 MB limit');
        }
        $this->assertMime($mime);

        $src = imagecreatefromstring($bytes);
        if (false === $src) {
            throw new \InvalidArgumentException('Photo data is not a readable image');
        }

        $width = imagesx($src);
        $height = imagesy($src);
        $dst = $src;
        // imagesx()/imagesy() are at least 1 for any image GD could read.
        $scale = min(1.0, self::MAX_DIMENSION / max($width, $height));
        if ($scale < 1.0) {
            $scaled = imagescale($src, (int) max(1, round($width * $scale)));
            if (false !== $scaled) {
                imagedestroy($src);
                $dst = $scaled;
            }
        }

        // Re-encode per mime, capturing GD's stdout into a string.
        ob_start();
        try {
            match ($mime) {
                'image/jpeg' => imagejpeg($dst, null, 85),
                'image/png' => imagepng($dst),
                'image/webp' => imagewebp($dst, null, 85),
                default => throw new \InvalidArgumentException('Unsupported photo type'),
            };
            $data = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        } finally {
            imagedestroy($dst);
        }

        $dir = $this->projectDir . '/var/contacts/' . $userId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create photo directory: ' . $dir);
        }

        $name = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_MIME[$mime];
        file_put_contents($dir . '/' . $name, $data);

        return 'contacts/' . $userId . '/' . $name;
    }

    /**
     * Deletes var/<relativePath> when it exists. Path traversal ('..') is
     * refused silently so purge paths cannot abort on a bad value.
     */
    public function delete(string $relativePath): void
    {
        if (str_contains($relativePath, '..')) {
            return;
        }
        $full = $this->projectDir . '/var/' . ltrim($relativePath, '/');
        if (is_file($full)) {
            @unlink($full);
        }
    }
}
