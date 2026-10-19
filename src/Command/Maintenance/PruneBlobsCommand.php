<?php

declare(strict_types=1);

namespace App\Command\Maintenance;

use App\Domain\Helper\UploadStorage;
use App\Repository\Mail\MessagePartRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\UploadedBlobRepository;
use App\Service\Mail\AttachmentThumbnailer;
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
 *   2. Orphaned files — files in the three stores with no row pointing at
 *      them. Filesystem-driven.
 *
 * Both respect the same retention window, and the window is what makes the
 * second pass safe: every writer creates the file before committing the row,
 * so a file younger than the cutoff may simply be waiting for its INSERT.
 * A week of slack is many orders of magnitude more than that gap.
 *
 * The second pass only looks where the three writers write: the per-account
 * trees, uploads/<account>/, attachments/<account>/ and raw/<account>/, and
 * in them only files laid out the way the writer lays them out. The roots are
 * shared volumes, and other owners keep files there that no column of ours
 * names: profile pictures (uploads/avatars), signature images
 * (uploads/signatures), appearance backgrounds (uploads/backgrounds). Until
 * #214 the sweep walked the whole root, took those for orphans and deleted
 * them every Sunday, along with every attachment thumbnail (attachments/thumbs),
 * which the third pass below now handles by its own rule.
 *
 * The roots are under APP_STORAGE_DIR, the same directory the writers use
 * and the prefix of every path the three columns hold. They were hardcoded
 * as var/… until #233, so an install with another storage dir swept
 * directories nothing wrote to and reclaimed nothing.
 *
 * A third pass clears attachment thumbnails (attachments/thumbs/<part>.webp,
 * AttachmentThumbnailer) whose part no longer exists. Nothing else deletes
 * them, and until #228 they were only cleared by accident, along with the
 * thumbnails of parts that still existed (#232).
 *
 * The road not taken is a deny-list of those directories. It would have been
 * a smaller diff, and it fails the same way the original did: the next owner
 * who adds a directory under a root (sealed messages under raw/ are one) is
 * swept until somebody remembers to extend the list. Here the default for a
 * path nobody claimed is to leave it alone; a leaked file costs disk, a swept
 * one costs somebody's data.
 */
#[AsCommand(
    name: 'app:prune:blobs',
    description: 'Delete expired uploads and orphaned files from the blob stores',
)]
final class PruneBlobsCommand extends Command
{
    private const int DEFAULT_DAYS = 7;

    /**
     * The stores this command sweeps, by their directory under APP_STORAGE_DIR,
     * each with the shape of a path its writer produces, relative to it. Which column references files in each is
     * the repositories' business — see referencedPaths().
     *
     * The first segment is always the account id, so a directory under a root
     * whose name is not a number is not ours and is never entered. The rest
     * mirrors the writer exactly:
     *
     *   uploads      <account>/<32 hex>.blob              UploadStorage
     *   attachments  <account>/<mailbox>/<uid>/<name>     AttachmentStorageHelper
     *   raw          <account>/<id div 1000>/<id>.eml     RawMessageStorage
     *
     * A file inside an account tree that does not match is left alone too:
     * if a writer ever changes its layout, the pattern here has to follow
     * before anything in the new shape can be swept, not after.
     *
     * @var array<string, non-empty-string>
     */
    private const array STORES = [
        'uploads'     => '#^\d+/[0-9a-f]{32}\.blob$#',
        'attachments' => '#^\d+/\d+/\d+/[^/]+$#',
        'raw'         => '#^\d+/\d+/\d+\.eml$#',
    ];

    /** How many part ids one existence query asks about. */
    private const int ID_BATCH = 1000;

    /** The largest message_part.id there can be (a Postgres integer). */
    private const int MAX_PART_ID = 2_147_483_647;

    public function __construct(
        private readonly UploadedBlobRepository $repository,
        private readonly MessagePartRepository $messageParts,
        private readonly MessageRepository $messages,
        private readonly UploadStorage $storage,
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
        private readonly string $storageDir,
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
            foreach (self::STORES as $store => $layout) {
                [$count, $size] = $this->sweepOrphans($io, $store, $layout, $cutoff, $dryRun);
                $files += $count;
                $bytes += $size;
            }

            [$count, $size] = $this->sweepThumbnails($io, $cutoff, $dryRun);
            $files += $count;
            $bytes += $size;
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
     * Pass 2: files in a store's account trees that no row points at any more.
     *
     * @param non-empty-string $layout
     *
     * @return array{int, int} files, bytes
     */
    private function sweepOrphans(SymfonyStyle $io, string $store, string $layout, \DateTimeImmutable $cutoff, bool $dryRun): array
    {
        $root         = $this->storageDir.'/'.$store;
        $absoluteRoot = $this->projectDir.'/'.$root;

        if (false === is_dir($absoluteRoot)) {
            $io->text(sprintf('Orphans in %s: store does not exist yet.', $root));

            return [0, 0];
        }

        $referenced = $this->referencedPaths($store, $root);

        $files = 0;
        $bytes = 0;

        $accounts = $this->accountDirectories($absoluteRoot);

        foreach ($accounts as $account) {
            foreach ($this->walk($account) as $file) {
                $relative = $this->relativeTo($absoluteRoot, $file);

                if (1 !== preg_match($layout, $relative)) {
                    continue;
                }

                // A file younger than the cutoff may be one whose row has not
                // been committed yet. Leave it for the next run.
                if ($file->getMTime() >= $cutoff->getTimestamp()) {
                    continue;
                }

                if (true === isset($referenced[$root.'/'.$relative])) {
                    continue;
                }

                ++$files;
                $bytes += $file->getSize();

                if (false === $dryRun) {
                    @unlink($file->getPathname());
                }
            }
        }

        if (false === $dryRun && $files > 0) {
            foreach ($accounts as $account) {
                $this->removeEmptyDirectories($account);
            }
        }

        $io->text(sprintf('Orphans in %s: %d (%s).', $root, $files, $this->humanBytes($bytes)));

        return [$files, $bytes];
    }

    /**
     * Every path a row still points at under this root, as a lookup set.
     *
     * One store, one repository: each of the three columns belongs to a
     * different entity, and asking each owner for its own paths is what keeps
     * a table name out of this command. The set is held in memory, so this
     * scales with referenced files rather than with message size — a few MB at
     * 60k attachments.
     *
     * @return array<string, true>
     */
    private function referencedPaths(string $store, string $root): array
    {
        return match ($store) {
            'uploads'     => $this->repository->findReferencedPaths($root),
            'attachments' => $this->messageParts->findReferencedStoragePaths($root),
            'raw'         => $this->messages->findReferencedRawPaths($root),
            // Reached only by adding a store to STORES without saying who owns
            // it. Loud, because the silent answer would be an empty keep-list,
            // and an empty keep-list deletes the store.
            default => throw new \LogicException(sprintf('No repository owns the blob store "%s".', $store)),
        };
    }

    /**
     * Pass 3: thumbnails of attachments that no longer exist (#232).
     *
     * A thumbnail is named by its part's id and nothing deletes it with the
     * part, so it is kept exactly while a message_part row with that id
     * exists. Only files named `<id>.webp` are considered; anything else in
     * the directory is left alone. The retention window applies as in the
     * other passes, although a thumbnail is only ever written for a part that
     * is already committed.
     *
     * @return array{int, int} files, bytes
     */
    private function sweepThumbnails(SymfonyStyle $io, \DateTimeImmutable $cutoff, bool $dryRun): array
    {
        $directory = $this->projectDir.'/'.$this->storageDir.'/'.AttachmentThumbnailer::DIRECTORY;

        if (false === is_dir($directory)) {
            $io->text('Orphaned thumbnails: none yet.');

            return [0, 0];
        }

        /** @var array<int, \SplFileInfo> $candidates part id => file */
        $candidates = [];

        foreach (new \FilesystemIterator($directory) as $file) {
            if (
                $file instanceof \SplFileInfo
                && true === $file->isFile()
                && 1 === preg_match('/^(\d{1,10})\.webp$/', $file->getFilename(), $match)
                // message_part.id is a Postgres integer: a larger number is no
                // thumbnail of ours, and asking about it would fail the query.
                && (int) $match[1] <= self::MAX_PART_ID
                && $file->getMTime() < $cutoff->getTimestamp()
            ) {
                $candidates[(int) $match[1]] = $file;
            }
        }

        $files = 0;
        $bytes = 0;

        foreach (array_chunk(array_keys($candidates), self::ID_BATCH) as $ids) {
            $existing = $this->messageParts->findExistingIds($ids);

            foreach ($ids as $id) {
                if (true === isset($existing[$id])) {
                    continue;
                }

                ++$files;
                $bytes += $candidates[$id]->getSize();

                if (false === $dryRun) {
                    @unlink($candidates[$id]->getPathname());
                }
            }
        }

        $io->text(sprintf('Orphaned thumbnails: %d (%s).', $files, $this->humanBytes($bytes)));

        return [$files, $bytes];
    }

    /**
     * The per-account directories directly under a root: the ones named by a
     * number. Everything else at that level belongs to someone else.
     *
     * @return list<string> absolute paths
     */
    private function accountDirectories(string $root): array
    {
        $accounts = [];

        foreach (new \FilesystemIterator($root) as $entry) {
            if (
                $entry instanceof \SplFileInfo
                && true === $entry->isDir()
                && false === $entry->isLink()
                && 1 === preg_match('/^\d+$/', $entry->getFilename())
            ) {
                $accounts[] = $entry->getPathname();
            }
        }

        return $accounts;
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
     *
     * Called per account tree, never on a root, so another owner's directory
     * that happens to be empty right now is not removed from under it. The
     * account directory itself goes once it is empty; the writers recreate it.
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

        @rmdir($root);
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
