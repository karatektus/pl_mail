<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Stores original RFC822 message bytes on disk, alongside attachments.
 *
 * Deliberately NOT a database column. A raw message carries its attachments
 * base64-inlined, so a 5 MB PDF becomes ~6.8 MB of text — putting that in
 * Postgres would drag every attachment back into the database that
 * AttachmentStorageHelper exists to keep out of it.
 *
 * Fanned out by message id modulo 1000 so no single directory ends up with
 * hundreds of thousands of entries.
 */
readonly class RawMessageStorage
{
    private const int FANOUT = 1000;

    public function __construct(
        private string $projectDir,
        private string $storageDir,
    ) {
    }

    /**
     * @return string path relative to the project root, for storing on the entity
     */
    public function store(int $accountId, int $messageId, string $content): string
    {
        $relativeDirectory = sprintf(
            $this->storageDir.'/raw/%d/%d',
            $accountId,
            intdiv($messageId, self::FANOUT),
        );

        $directory = $this->projectDir.'/'.$relativeDirectory;

        if (false === is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $relativePath = sprintf('%s/%d.eml', $relativeDirectory, $messageId);

        file_put_contents($this->projectDir.'/'.$relativePath, $content);

        return $relativePath;
    }

    public function getAbsolutePath(string $relativePath): string
    {
        return $this->projectDir.'/'.$relativePath;
    }

    public function exists(string $relativePath): bool
    {
        return is_file($this->getAbsolutePath($relativePath));
    }

    /**
     * Remove a stored .eml, when the row that named it is going away.
     *
     * Guarded the same way AttachmentStorageHelper::delete() is, and for the
     * same reason: rawPath is a column other code writes provider schemes into,
     * and a path that is not one of ours must be ignored rather than resolved
     * against the project root and unlinked.
     */
    public function delete(?string $relativePath): void
    {
        if (null === $relativePath || false === str_starts_with($relativePath, $this->storageDir.'/raw/')) {
            return;
        }

        if (true === str_contains($relativePath, '..')) {
            return;
        }

        $path = $this->getAbsolutePath($relativePath);

        if (true === is_file($path)) {
            unlink($path);
        }
    }
}
