<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Helper\UploadStorage;
use App\Repository\UploadedBlobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reclaim blobs a client uploaded but never attached to anything.
 *
 * RFC 8620 §6.1 lets the server expire unreferenced uploads. Without this an
 * authenticated client can fill the disk one POST at a time, since nothing
 * else ever deletes them.
 *
 * Deliberately conservative by default: a week is far longer than any client
 * needs between uploading an attachment and sending the message it belongs to.
 */
#[AsCommand(
    name: 'app:jmap:prune-uploads',
    description: 'Delete unreferenced JMAP blob uploads older than the retention window',
)]
final class PruneJmapUploadsCommand extends Command
{
    private const int DEFAULT_DAYS = 7;

    public function __construct(
        private readonly UploadedBlobRepository $repository,
        private readonly UploadStorage $storage,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention window in days', (string) self::DEFAULT_DAYS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be deleted without deleting it');
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
        $before = new \DateTimeImmutable(sprintf('-%d days', $days));
        $blobs = $this->repository->findOlderThan($before);

        if (0 === count($blobs)) {
            $io->success('Nothing to prune.');

            return Command::SUCCESS;
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

        $io->success(sprintf(
            '%s %d upload(s), %s.',
            true === $dryRun ? 'Would prune' : 'Pruned',
            count($blobs),
            $this->humanBytes($bytes),
        ));

        return Command::SUCCESS;
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
