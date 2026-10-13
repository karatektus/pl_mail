<?php

declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\Drive\DriveFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Where Drive files live on disk: <project>/<APP_STORAGE_DIR>/drive/<user>/<file>.
 *
 * Its own directory, deliberately NOT under var/uploads. app:prune-blobs
 * sweeps var/uploads for files no `uploaded_blob` row references, and every
 * Drive file would have been one of those — deleted the first Sunday after it
 * was uploaded. In Docker the directory is the `app_drive` volume.
 *
 * The on-disk name is the row id, never the user's filename, so a name like
 * "../../etc/passwd" is only ever a string in a column.
 */
final readonly class DriveStorage
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%env(APP_STORAGE_DIR)%')]
        private string $storageDir,
    ) {
    }

    public function path(DriveFile $file): string
    {
        return sprintf('%s/%s/drive/%d/%d', $this->projectDir, $this->storageDir, $file->usr->id, $file->id);
    }

    public function store(UploadedFile $uploaded, DriveFile $file): void
    {
        $path = $this->path($file);
        $dir = \dirname($path);

        if (false === is_dir($dir) && false === @mkdir($dir, 0750, true) && false === is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create %s', $dir));
        }

        $uploaded->move($dir, basename($path));
    }

    /**
     * The same as store(), for bytes that arrived as a request body (the
     * Android app uploads raw, like JMAP's own upload endpoint) rather than
     * as a multipart form field.
     */
    public function storeContent(string $content, DriveFile $file): void
    {
        $path = $this->path($file);
        $dir = \dirname($path);

        if (false === is_dir($dir) && false === @mkdir($dir, 0750, true) && false === is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create %s', $dir));
        }

        if (false === file_put_contents($path, $content)) {
            throw new \RuntimeException(sprintf('Could not write %s', $path));
        }
    }

    /** Moves a finished temporary file into place (streamed API uploads). */
    public function storeFile(string $tempPath, DriveFile $file): void
    {
        $path = $this->path($file);
        $dir = \dirname($path);
        if (false === is_dir($dir) && false === @mkdir($dir, 0750, true) && false === is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create %s', $dir));
        }
        if (false === @rename($tempPath, $path)) {
            if (false === @copy($tempPath, $path)) {
                throw new \RuntimeException(sprintf('Could not write %s', $path));
            }
            @unlink($tempPath);
        }
        @chmod($path, 0640);
    }

    public function exists(DriveFile $file): bool
    {
        return is_file($this->path($file));
    }

    public function delete(DriveFile $file): void
    {
        $path = $this->path($file);

        if (is_file($path)) {
            unlink($path);
        }
    }
}
