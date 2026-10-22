<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores contact photos under {APP_STORAGE_DIR}/uploads/contacts/{userId}/.
 * JPEG/PNG/WebP only, 5 MB max, downscaled so the longest side is <= 512 px
 * (GD). Filenames are random hex; the stored relative path (e.g.
 * 'contacts/42/abcdef.jpg') goes into Contact::$photoPath, and absolutePath()
 * is the one place it becomes a file.
 *
 * **Under uploads, because two containers use it.** The worker writes the
 * photos a sync brings in and the web container serves them, and var/uploads
 * is a volume both mount (as AvatarStorage relies on). The first version kept
 * them in var/contacts, which is in neither volume: every synced photo was
 * written where the web container could not see it and answered 404, and an
 * uploaded one went with the container on its next recreate.
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
        #[Autowire('%env(APP_STORAGE_DIR)%')]
        private string $storageDir = 'var',
    ) {
    }

    /**
     * Where a Contact::$photoPath is on disk. Null for a path that tries to
     * leave the photo directory.
     */
    public function absolutePath(string $relativePath): ?string
    {
        if ('' === $relativePath || str_contains($relativePath, '..')) {
            return null;
        }

        return $this->projectDir . '/' . trim($this->storageDir, '/') . '/uploads/' . ltrim($relativePath, '/');
    }

    /** Whether the photo at a Contact::$photoPath is on disk. */
    public function exists(?string $relativePath): bool
    {
        $full = null === $relativePath ? null : $this->absolutePath($relativePath);

        return null !== $full && is_file($full);
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

        $dir = (string) $this->absolutePath('contacts/' . $userId);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create photo directory: ' . $dir);
        }

        $name = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_MIME[$mime];
        file_put_contents($dir . '/' . $name, $data);

        return 'contacts/' . $userId . '/' . $name;
    }

    /**
     * The stored photo at a Contact::$photoPath, for sending to a server or
     * an export. Null for no path, a missing file, or a path that is not one
     * of ours.
     */
    public function read(?string $relativePath): ?ContactPhoto
    {
        $full = null === $relativePath ? null : $this->absolutePath($relativePath);

        if (null === $full) {
            return null;
        }

        $mime = array_search(strtolower((string) pathinfo($relativePath, \PATHINFO_EXTENSION)), self::ALLOWED_MIME, true);

        if (false === $mime || false === is_file($full)) {
            return null;
        }

        $bytes = file_get_contents($full);

        return false === $bytes || '' === $bytes ? null : new ContactPhoto($bytes, $mime);
    }

    /**
     * Deletes the photo when it exists. Path traversal ('..') is refused
     * silently so purge paths cannot abort on a bad value.
     */
    public function delete(string $relativePath): void
    {
        $full = $this->absolutePath($relativePath);
        if (null !== $full && is_file($full)) {
            @unlink($full);
        }
    }
}
