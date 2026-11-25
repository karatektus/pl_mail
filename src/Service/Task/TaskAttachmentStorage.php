<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Repository\Task\TaskAttachmentRepository;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Where a task's files live on disk.
 *
 * Under the attachments store, wherever APP_STORAGE_DIR puts it, so they stay on
 * the volume operators already keep (docs/install), in a folder of their own that
 * app:prune:blobs's mail layout does not match. The name on disk is minted here and never comes from the upload, so
 * no filename can walk out of the directory.
 *
 * A task or a list can go through a database cascade — a mail account deleted, a
 * connection removed — that nothing in PHP hears about, so a file can outlive its
 * row. pruneOrphans() is what collects those, from app:prune:blobs.
 */
final readonly class TaskAttachmentStorage
{
    private const string DIRECTORY = 'tasks';

    /** A file younger than this may be an upload whose row is still being written. */
    private const string GRACE = '-1 day';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string                   $projectDir,
        #[Autowire('%env(APP_STORAGE_DIR)%')]
        private string                   $storageDir,
        private Filesystem               $filesystem,
        private TaskAttachmentRepository $attachments,
    ) {}

    /** @return string the name to keep on the row */
    public function store(UploadedFile $file): string
    {
        $name = Uuid::v7()->toRfc4122();

        $file->move($this->directory(), $name);

        return $name;
    }

    public function path(string $storedName): string
    {
        return sprintf('%s/%s', $this->directory(), basename($storedName));
    }

    public function remove(string $storedName): void
    {
        $this->filesystem->remove($this->path($storedName));
    }

    /** @return array{int, int} how many files, and how many bytes */
    public function pruneOrphans(DateTimeImmutable $now, bool $dryRun): array
    {
        if (false === is_dir($this->directory())) {
            return [0, 0];
        }

        $kept   = array_flip($this->attachments->storedNames());
        $cutoff = $now->modify(self::GRACE)->getTimestamp();
        $files  = 0;
        $bytes  = 0;

        foreach (new \DirectoryIterator($this->directory()) as $file) {
            if (false === $file->isFile() || true === isset($kept[$file->getFilename()]) || $file->getMTime() > $cutoff) {
                continue;
            }

            ++$files;
            $bytes += $file->getSize();

            if (false === $dryRun) {
                $this->filesystem->remove($file->getPathname());
            }
        }

        return [$files, $bytes];
    }

    private function directory(): string
    {
        return sprintf('%s/%s/attachments/%s', $this->projectDir, $this->storageDir, self::DIRECTORY);
    }
}
