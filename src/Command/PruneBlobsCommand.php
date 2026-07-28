<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Helper\UploadStorage;
use App\Repository\UploadedBlobRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reclaims disk for every blob store that outlives its database row.
 *
 * Three directories hold bytes we deliberately keep out of Postgres, each
 * addressed by a column somewhere:
 *
 *   var/uploads      uploaded_blob.path        staged JMAP uploads
 *   var/attachments  message_part.storage_path attachment bodies
 *   var/raw          message.raw_path          original RFC822 source
 *
 * Rows go away without their files. Deleting an account is one DELETE that
 * cascades in the database, drafts drop attachments, messages get expunged
 * upstream — none of that unlinks anything, and an orphaned file is invisible:
 * nothing points at it, so nothing ever notices it filling the disk.
 *
 * Replaces app:jmap:prune-uploads, which only handled the first of the three
 * and only from the row side.
 *
 * Two passes, because there are two ways to leak:
 *
 *   1. Expired staging uploads — rows (and files) for blobs a client uploaded
 *      but never attached to anything. RFC 8620 §6.1 lets the server expire
 *      these. Row-driven.
 *   2. Orphaned files — files under the three roots with no row pointing at
 *      them. Filesystem-driven.
 *
 * Both respect the same retention window, and the window is what makes the
 * second pass safe: every writer creates the file before committing the row,
 * so a file younger than the cutoff may simply be waiting for its INSERT.
 * A week of slack is many orders of magnitude more than that gap.
 */
#[AsCommand(
    name: 'app:prune:blobs',
    description: 'Delete expired uploads and orphaned files from the blob stores',
)]
final class PruneBlobsCommand extends Command
{
    private const int DEFAULT_DAYS = 7;

    /**
     * Root directory => the column that references files in it.
     *
     * @var array<string, array{table: string, column: string}>
     */
    private const array STORES = [
        'var/uploads' => ['table' => 'uploaded_blob', 'column' => 'path'],
        'var/attachments' => ['table' => 'message_part', 'column' => 'storage_path'],
        'var/raw' => ['table' => 'message', 'column' => 'raw_path'],
    ];

    public function __construct(
        private readonly UploadedBlobRepository $repository,
        private readonly UploadStorage $storage,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention window in days', (string) self::DEFAULT_DAYS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without deleting it')
            ->addOption('skip-uploads', null, InputOption::VALUE_NONE, 'Skip the expired-upload pass')
            ->addOption('skip-orphans', null, InputOption::VALUE_NONE, 'Skip the orphaned-file sweep');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = (int) $input->getOption('days');

        if ($days < 1) {
            $io->error('--days must be at least 1.');

            return Command::INVALID;
        }

        $dryRun = true === $input->getOption('dry-run');
        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $days));

        if (true === $dryRun) {
            $io->note('Dry run — nothing will be deleted.');
        }

        $files = 0;
        $bytes = 0;

        if (false === $input->getOption('skip-uploads')) {
            [$count, $size] = $this->pruneExpiredUploads($io, $cutoff, $dryRun);
            $files += $count;
            $bytes += $size;
        }

        if (false === $input->getOption('skip-orphans')) {
            foreach (array_keys(self::STORES) as $root) {
                [$count, $size] = $this->sweepOrphans($io, $root, $cutoff, $dryRun);
                $files += $count;
                $bytes += $size;
            }
        }

        $io->success(sprintf(
            '%s %d file(s), %s.',
            true === $dryRun ? 'Would reclaim' : 'Reclaimed',
            $files,
            $this->humanBytes($bytes),
        ));

        return Command::SUCCESS;
    }

    /**
     * Pass 1: staged uploads nobody ever attached to a message.
     *
     * @return array{int, int} files, bytes
     */
    private function pruneExpiredUploads(SymfonyStyle $io, \DateTimeImmutable $cutoff, bool $dryRun): array
    {
        $blobs = $this->repository->findOlderThan($cutoff);

        if (0 === count($blobs)) {
            $io->text('Expired uploads: none.');

            return [0, 0];
        }

        $bytes = 0;

        foreach ($blobs as $blob) {
            $bytes += $blob->size;

            if (true === $dryRun) {
                continue;
            }

            // File first: a leftover row with no file is recoverable, a
            // leftover file with no row is invisible and never reclaimed.
            $this->storage->delete($blob->path);
            $this->em->remove($blob);
        }

        if (false === $dryRun) {
            $this->em->flush();
        }

        $io->text(sprintf('Expired uploads: %d (%s).', count($blobs), $this->humanBytes($bytes)));

        return [count($blobs), $bytes];
    }

    /**
     * Pass 2: files under $root that no row points at any more.
     *
     * @return array{int, int} files, bytes
     */
    private function sweepOrphans(SymfonyStyle $io, string $root, \DateTimeImmutable $cutoff, bool $dryRun): array
    {
        $absoluteRoot = $this->projectDir.'/'.$root;

        if (false === is_dir($absoluteRoot)) {
            $io->text(sprintf('Orphans in %s: store does not exist yet.', $root));

            return [0, 0];
        }

        $referenced = $this->referencedPaths($root);

        $files = 0;
        $bytes = 0;

        foreach ($this->walk($absoluteRoot) as $file) {
            // A file younger than the cutoff may be one whose row has not been
            // committed yet. Leave it for the next run.
            if ($file->getMTime() >= $cutoff->getTimestamp()) {
                continue;
            }

            $path = $root.'/'.$this->relativeTo($absoluteRoot, $file);

            if (true === isset($referenced[$path])) {
                continue;
            }

            ++$files;
            $bytes += $file->getSize();

            if (false === $dryRun) {
                @unlink($file->getPathname());
            }
        }

        if (false === $dryRun && $files > 0) {
            $this->removeEmptyDirectories($absoluteRoot);
        }

        $io->text(sprintf('Orphans in %s: %d (%s).', $root, $files, $this->humanBytes($bytes)));

        return [$files, $bytes];
    }

    /**
     * Every path a row in this store still points at, as a lookup set.
     *
     * One streamed sequential scan per store rather than a batched IN() per
     * thousand files: none of these columns is indexed, and indexing them to
     * serve a maintenance command would tax every write to buy nothing the
     * rest of the year.
     *
     * The set is held in memory, so this scales with referenced files rather
     * than with message size — a few MB at 60k attachments. The LIKE keeps
     * provider-scheme values (gmail://, msgraph://) out of it, since those
     * name no local file.
     *
     * @return array<string, true>
     */
    private function referencedPaths(string $root): array
    {
        $store = self::STORES[$root];

        $result = $this->connection->executeQuery(sprintf(
            'SELECT %1$s FROM %2$s WHERE %1$s LIKE :prefix',
            $store['column'],
            $store['table'],
        ), ['prefix' => $root.'/%']);

        $referenced = [];

        foreach ($result->iterateColumn() as $path) {
            $referenced[$path] = true;
        }

        return $referenced;
    }

    /**
     * @return iterable<\SplFileInfo>
     */
    private function walk(string $directory): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && true === $file->isFile()) {
                yield $file;
            }
        }
    }

    private function relativeTo(string $root, \SplFileInfo $file): string
    {
        return ltrim(substr($file->getPathname(), strlen($root)), '/');
    }

    /**
     * Attachments fan out one directory per message and raw messages one per
     * thousand ids, so a sweep leaves behind a tree of empty directories that
     * would otherwise never be collected.
     */
    private function removeEmptyDirectories(string $root): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && true === $entry->isDir()) {
                @rmdir($entry->getPathname());
            }
        }
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
