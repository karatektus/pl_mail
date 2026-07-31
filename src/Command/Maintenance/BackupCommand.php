<?php

declare(strict_types=1);

namespace App\Command\Maintenance;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * One command for the three things a plMail install cannot be rebuilt without.
 *
 * The README has always told people to back up the database and the encryption
 * key separately, and then left them to work out how. That is the most
 * dangerous instruction in the project to get wrong, because both halves fail
 * silently: a database dump alone restores every mailbox with credentials
 * nothing can decrypt, and a key alone restores nothing at all.
 *
 * What goes in:
 *
 *   database.sql   pg_dump of everything
 *   storage/       APP_STORAGE_DIR — attachments, raw messages, uploads,
 *                  avatars. Attachment paths are stored relative to the
 *                  project root, so this has to travel with the database or
 *                  every attachment 404s after a restore.
 *   secrets.env    the generated secrets, including APP_ENCRYPTION_KEY
 *
 * **secrets.env is written separately and last**, and the command says so
 * loudly. Storing it beside the dump recreates exactly the situation
 * encryption-at-rest exists to prevent: one stolen archive discloses both the
 * ciphertext and the key. Encrypting mailbox passwords is pointless if the
 * backup helpfully staples the key to them.
 *
 * Not scheduled. A backup that runs itself onto the same disk as the thing it
 * is backing up is a false sense of security, and where else it should go is a
 * decision only the operator can make.
 */
#[AsCommand(
    name: 'app:backup',
    description: 'Write a restorable snapshot: database, stored files, and (separately) the secrets.',
)]
final class BackupCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(APP_STORAGE_DIR)%')]
        private readonly string $storageDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('destination', null, 'Directory to write the backup into')
            ->addOption('skip-secrets', null, InputOption::VALUE_NONE, 'Do not copy secrets.env — use when the key is already backed up elsewhere')
            ->addOption('skip-storage', null, InputOption::VALUE_NONE, 'Database only; leave attachments and raw messages out');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Backup');

        $destination = $this->resolveDestination($input->getArgument('destination'));

        if (false === @mkdir($destination, 0700, true) && false === is_dir($destination)) {
            $io->error(sprintf('Cannot create %s', $destination));

            return Command::FAILURE;
        }

        // 0700 because of what is about to be written here.
        @chmod($destination, 0700);

        if (Command::SUCCESS !== $this->dumpDatabase($io, $destination)) {
            return Command::FAILURE;
        }

        if (false === $input->getOption('skip-storage')) {
            $this->copyStorage($io, $destination);
        }

        $capturedKey = false;

        if (false === $input->getOption('skip-secrets')) {
            $capturedKey = $this->copySecrets($io, $destination);
        }

        $io->success(sprintf('Backup written to %s', $destination));

        if (true === $capturedKey) {
            $io->warning(
                "secrets.env holds APP_ENCRYPTION_KEY, which decrypts every mailbox password and\n".
                "OAuth token in database.sql. Move it somewhere the database dump is not.\n".
                'Kept together, the pair is worth exactly as much to a thief as an unencrypted backup.',
            );
        } elseif (false === $input->getOption('skip-secrets')) {
            // The dangerous case, and the reason this is a check rather than a
            // fixed message: an install that supplies APP_ENCRYPTION_KEY from
            // the environment has nothing to copy, so a backup taken here looks
            // complete and restores to a database whose credentials nothing can
            // read. Saying nothing would be the worst option available.
            $io->warning(
                "APP_ENCRYPTION_KEY is NOT in this backup — this install supplies it from the\n".
                "environment rather than the generated secrets file. Without it, database.sql\n".
                "restores every mailbox with credentials that cannot be decrypted.\n".
                'Back it up from wherever you configure it.',
            );
        }

        return Command::SUCCESS;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function resolveDestination(mixed $argument): string
    {
        if (true === is_string($argument) && '' !== $argument) {
            return rtrim($argument, '/');
        }

        return sprintf(
            '%s/var/backups/%s',
            $this->projectDir,
            (new \DateTimeImmutable())->format('Y-m-d_His'),
        );
    }

    /**
     * pg_dump over the parsed DATABASE_URL rather than a hand-built DSN, so a
     * connection the app can use is a connection the dump can use.
     *
     * The password goes through PGPASSWORD in the environment, not the command
     * line, where it would be visible in `ps` to every user on the host.
     */
    private function dumpDatabase(SymfonyStyle $io, string $destination): int
    {
        $params = $this->connection->getParams();
        $target = $destination.'/database.sql';

        $process = new Process(
            [
                'pg_dump',
                '--host='.($params['host'] ?? 'localhost'),
                '--port='.(string) ($params['port'] ?? 5432),
                '--username='.($params['user'] ?? 'app'),
                '--dbname='.($params['dbname'] ?? 'app'),
                '--no-owner',
                '--no-privileges',
                '--file='.$target,
            ],
            env: ['PGPASSWORD' => (string) ($params['password'] ?? '')],
            timeout: null,
        );

        $io->text('Dumping the database…');
        $process->run();

        if (false === $process->isSuccessful()) {
            $io->error([
                'pg_dump failed.',
                trim($process->getErrorOutput()),
                'If pg_dump is missing, run this inside the database container instead.',
            ]);

            return Command::FAILURE;
        }

        @chmod($target, 0600);
        $io->text(sprintf('  database.sql (%s)', $this->humanSize($target)));

        return Command::SUCCESS;
    }

    private function copyStorage(SymfonyStyle $io, string $destination): void
    {
        $source = $this->projectDir.'/'.trim($this->storageDir, '/');

        foreach (['attachments', 'raw', 'uploads'] as $subdirectory) {
            $from = $source.'/'.$subdirectory;

            if (false === is_dir($from)) {
                continue;
            }

            $io->text(sprintf('Copying %s…', $subdirectory));

            // cp -a: preserves the directory bucketing the storage paths in the
            // database point at. A flattened copy restores files nothing can
            // find.
            $process = new Process(['cp', '-a', $from, $destination.'/'], timeout: null);
            $process->run();

            if (false === $process->isSuccessful()) {
                $io->warning(sprintf('Could not copy %s: %s', $subdirectory, trim($process->getErrorOutput())));
            }
        }
    }

    /** @return bool whether the copied file actually carries the encryption key */
    private function copySecrets(SymfonyStyle $io, string $destination): bool
    {
        $source = $this->projectDir.'/var/secrets/generated.env';

        if (false === is_file($source)) {
            $io->note('No generated secrets file — this install runs entirely on secrets supplied from the environment.');

            return false;
        }

        $target = $destination.'/secrets.env';

        if (false === @copy($source, $target)) {
            $io->warning('Could not copy the secrets file.');

            return false;
        }

        @chmod($target, 0600);
        $io->text('  secrets.env');

        // Presence is checked rather than assumed: the file holds whatever was
        // generated, and anything supplied through the environment is
        // deliberately never written into it.
        return str_contains((string) @file_get_contents($target), 'APP_ENCRYPTION_KEY=');
    }

    private function humanSize(string $path): string
    {
        $bytes = (int) @filesize($path);
        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            ++$index;
        }

        return sprintf('%.1f %s', $bytes, $units[$index]);
    }
}
