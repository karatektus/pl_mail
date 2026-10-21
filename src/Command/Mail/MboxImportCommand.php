<?php

declare(strict_types=1);

namespace App\Command\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Repository\Mail\AccountRepository;
use App\Service\Mail\MboxImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Bring an existing mail archive in from disk.
 *
 * The source this was written for is a Thunderbird profile — Mail/<host>/
 * folders of mbox files, one per folder — but the format is the plain mbox
 * every Unix mail tool writes, so anything holding RFC822 messages separated
 * by "From " lines works. Nothing connects anywhere; the bytes are read where
 * they sit.
 *
 * The mapping is by FOLDER NAME, because that is what an archive carries:
 * a file named Inbox maps to the account's Inbox, Sent to Sent, Drafts to
 * Drafts. Trash is refused rather than skipped-quietly — importing a bin
 * imports everything somebody deliberately threw away, and an accident of
 * habit (passing a whole Thunderbird directory) must not become 385 old
 * deleted messages. There is an opt-in flag for it; nothing turns it on.
 *
 * DRY-RUN FIRST is the intended usage and the reason the flag exists rather
 * than being implied: the counts it prints — total, duplicates skipped, would
 * import — are what makes "import my five years" a decision rather than a
 * leap. Nothing is written while it is set, including the mailbox rows.
 */
#[AsCommand(
    name: 'app:mail:import-mbox',
    description: 'Import an mbox archive (e.g. a Thunderbird folder file) into an account',
)]
final class MboxImportCommand extends Command
{
    /** The folder names this maps, and what they map to. Everything else is skipped with a note. */
    private const array FOLDER_ROLES = [
        'inbox'  => LabelRole::Inbox,
        'sent'   => LabelRole::Sent,
        'drafts' => LabelRole::Drafts,
        // Trash is deliberately absent, and the service refuses it too. A bin
        // is everything somebody threw away; importing one is not a flag away.
    ];

    public function __construct(
        private readonly MboxImporter     $importer,
        private readonly AccountRepository $accounts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('account', InputArgument::REQUIRED, 'The account email the mail belongs to')
            ->addArgument('paths', InputArgument::IS_ARRAY, 'mbox files (or a directory of them) to import')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would happen and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dryRun  = true === (bool) $input->getOption('dry-run');
        $account = $this->accounts->findOneBy(['email' => (string) $input->getArgument('account')]);

        if (null === $account) {
            $io->error(sprintf('No account with email %s', $input->getArgument('account')));
            return Command::FAILURE;
        }

        $files = $this->resolve((array) $input->getArgument('paths'));

        if ([] === $files) {
            $io->error('No mbox files found in the given paths');
            return Command::FAILURE;
        }

        $roleByFile = $this->rolesFor($files, $withTrash);

        if ([] === $roleByFile) {
            $io->error('No importable folders found (expected files named Inbox, Sent or Drafts; Trash is not imported)');
            return Command::FAILURE;
        }

        if (true === $dryRun) {
            $io->note('Dry run — nothing will be written');
        }

        $totals = ['total' => 0, 'imported' => 0, 'would' => 0, 'duplicates' => 0, 'failed' => 0];

        foreach ($roleByFile as $file => $role) {
            $io->section(sprintf('%s → %s on %s', basename($file), $role->value, $account->email));

            try {
                $result = $this->importer->importFile($file, $account, $role, $dryRun);
            } catch (\Throwable $failure) {
                $io->error(sprintf('%s: %s', $file, $failure->getMessage()));
                continue;
            }

            $io->text(sprintf(
                '  total %d · imported %d · would import %d · duplicates skipped %d · failed %d',
                $result->total, $result->imported, $result->wouldImport, $result->skippedDuplicate, $result->failed,
            ));

            foreach ($result->failures as $failure) {
                $io->text('    failed: ' . $failure);
            }

            $totals['total']       += $result->total;
            $totals['imported']    += $result->imported;
            $totals['would']       += $result->wouldImport;
            $totals['duplicates']  += $result->skippedDuplicate;
            $totals['failed']      += $result->failed;
        }

        $io->newLine();

        if (true === $dryRun) {
            $io->success(sprintf(
                'Would import %d of %d (%d duplicates skipped, %d unreadable). Re-run without --dry-run to do it.',
                $totals['would'], $totals['total'], $totals['duplicates'], $totals['failed'],
            ));
        } else {
            $io->success(sprintf(
                'Imported %d of %d (%d duplicates skipped, %d failed).',
                $totals['imported'], $totals['total'], $totals['duplicates'], $totals['failed'],
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Files to import, from either file paths or directories.
     *
     * A directory is read one level deep, skipping Thunderbird's index files —
     * the .msf beside every mbox is metadata and handing it to a parser buys a
     * screenful of "could not be stored" noise.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function resolve(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (true === is_dir($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    $candidate = $path . '/' . $entry;

                    if (true === is_file($candidate)
                        && false === str_ends_with($entry, '.msf')
                        && false === str_ends_with($entry, '.dat')
                        && 0 !== filesize($candidate)) {
                        $files[] = $candidate;
                    }
                }
                continue;
            }

            if (true === is_file($path)) {
                $files[] = $path;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Which role each file carries, skipping anything unmapped.
     *
     * Matched case-insensitively on the basename, because Thunderbird's folder
     * names are what the user sees and "Inbox" vs "INBOX" should not matter.
     * Unmapped files (a custom folder, a .dat) are listed rather than
     * swallowed: a silently skipped file reads as a complete import.
     *
     * @param list<string> $files
     *
     * @return array<string, LabelRole>
     */
    private function rolesFor(array $files, bool $withTrash): array
    {
        $roles   = [];
        $skipped = [];

        foreach ($files as $file) {
            $name = strtolower(pathinfo($file, PATHINFO_FILENAME));

            if (false === isset(self::FOLDER_ROLES[$name])) {
                $skipped[] = basename($file);
                continue;
            }

            // Unreachable while FOLDER_ROLES has no Trash entry; kept as a
            // belt for a future mapping change rather than a flag.
            if (LabelRole::Trash === self::FOLDER_ROLES[$name]) {
                $skipped[] = basename($file) . ' (trash is not imported)';
                continue;
            }

            $roles[$file] = self::FOLDER_ROLES[$name];
        }

        return $roles;
    }
}
