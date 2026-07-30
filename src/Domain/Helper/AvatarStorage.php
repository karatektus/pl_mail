<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Where a user's avatar lives on disk.
 *
 * Same disk-not-database reasoning as the other blob stores, and the same
 * shared-storage requirement: the web container writes it and also serves it,
 * but the directory sits under var/uploads, which is already bound across the
 * services that need it.
 *
 * One file per user, replaced in place rather than versioned — nothing links to
 * an old avatar once a new one is uploaded.
 */
readonly class AvatarStorage
{
    /** GD-native raster formats only, matching the attachment thumbnailer. */
    public const array ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** Generous for a face, small enough that nobody uploads a RAW file. */
    public const int MAX_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private string $projectDir,
        private string $storageDir,
    ) {
    }

    public function isAcceptable(UploadedFile $file): bool
    {
        return $file->getSize() <= self::MAX_BYTES
            && in_array($file->getMimeType(), self::ALLOWED_MIME, true);
    }

    /**
     * @return string the stored filename, to put on the user
     */
    public function store(string $userId, UploadedFile $file): string
    {
        $directory = $this->directory($userId);

        if (false === is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->deleteAllFor($userId);

        // Random rather than fixed: the filename is in the URL, and a stable
        // one would be served from cache after a change.
        $filename = bin2hex(random_bytes(8)).'.'.($file->guessExtension() ?? 'img');

        $file->move($directory, $filename);

        return $filename;
    }

    /**
     * Same as store(), for bytes that arrived from somewhere other than an
     * upload — a file pulled out of a connected service, say.
     *
     * @return string the stored filename, to put on the user
     */
    public function storeContents(string $userId, string $originalName, string $contents): string
    {
        $directory = $this->directory($userId);

        if (false === is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->deleteAllFor($userId);

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $filename  = bin2hex(random_bytes(8)).'.'.('' === $extension ? 'img' : $extension);

        file_put_contents($directory.'/'.$filename, $contents);

        return $filename;
    }

    public function pathFor(string $userId, string $filename): string
    {
        return $this->directory($userId).'/'.$filename;
    }

    public function deleteAllFor(string $userId): void
    {
        foreach (glob($this->directory($userId).'/*') ?: [] as $existing) {
            if (true === is_file($existing)) {
                unlink($existing);
            }
        }
    }

    private function directory(string $userId): string
    {
        return $this->projectDir.'/'.$this->storageDir.'/uploads/avatars/'.$userId;
    }
}
