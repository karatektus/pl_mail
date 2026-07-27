<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Holding area for client-uploaded blobs that are not yet attached to a
 * message. Same disk-not-database reasoning as RawMessageStorage.
 *
 * Written by the web container and read by the workers when a submission
 * actually sends the attachment, so this directory has to be on shared
 * storage — see the volume mounts in compose.
 */
readonly class UploadStorage
{
    public function __construct(
        private string $projectDir,
    ) {
    }

    /**
     * @return string path relative to the project root
     */
    public function store(int $accountId, string $content): string
    {
        $relativeDirectory = sprintf('var/uploads/%d', $accountId);
        $directory = $this->projectDir.'/'.$relativeDirectory;

        if (false === is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Random name, not a sequential id: the file exists before the row it
        // belongs to, so there is no id to name it after yet.
        $relativePath = sprintf('%s/%s.blob', $relativeDirectory, bin2hex(random_bytes(16)));

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

    public function delete(string $relativePath): void
    {
        $path = $this->getAbsolutePath($relativePath);

        if (true === is_file($path)) {
            unlink($path);
        }
    }
}
