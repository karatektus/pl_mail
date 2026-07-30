<?php

namespace App\Command\Maintenance;

use App\Infrastructure\Setup\GeneratedSecretsFile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reset',
    description: 'Truncate all synced message data, optionally including mailbox structure, contacts and monitoring data',
)]
class ResetDataCommand extends Command
{
    /**
     * Everything generated on first run except the database password.
     *
     * That one stays: Postgres was initialised with it and keeps it in its own
     * data directory, so regenerating it would leave the app unable to log in
     * to the database it just reset. Wiping the database volume is the only way
     * to change it, and that is `docker compose down -v`, not this command.
     */
    private const array RESETTABLE_SECRETS = [
        'APP_SECRET',
        'APP_ENCRYPTION_KEY',
        'MERCURE_JWT_SECRET',
        'VAPID_PUBLIC_KEY',
        'VAPID_PRIVATE_KEY',
        'APP_PUBLIC_URL',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GeneratedSecretsFile $secrets,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mailboxes', null, InputOption::VALUE_NONE, 'Also delete mailbox structure (folders and labels)')
            ->addOption('contacts', null, InputOption::VALUE_NONE, 'Also delete harvested contacts')
            ->addOption('accounts', null, InputOption::VALUE_NONE, 'Also delete the accounts themselves, aliases included (implies --mailboxes)')
            ->addOption('keep-monitoring', null, InputOption::VALUE_NONE, 'Keep monitoring data (aggregated logs and process heartbeats)')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Back to first-run state: every table, every user, the stored files and the generated secrets');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (true === $input->getOption('full')) {
            return $this->fullReset($io, $input->isInteractive());
        }

        $io->warning('This will permanently delete synced data from the database.');

        // Accounts are the one thing a plain reset never touches — you would
        // have to re-enter every password to get back to a syncing app. Flag
        // only, never a prompt, and it takes the structure with it because a
        // label without its account is a foreign key violation waiting to
        // happen.
        $deleteAccounts = true === $input->getOption('accounts');

        $deleteMailboxes = $deleteAccounts || true === $input->getOption('mailboxes') || (
            $input->isInteractive() && $io->confirm(
                'Also delete mailbox structure (folders and labels)? If no, only messages and threads will be cleared.',
                false,
            )
        );

        $deleteContacts = true === $input->getOption('contacts') || (
            $input->isInteractive() && $io->confirm(
                'Also delete harvested contacts?',
                false,
            )
        );

        $resetMonitoring = true !== $input->getOption('keep-monitoring') && (
            !$input->isInteractive() || $io->confirm(
                'Also clear monitoring data (aggregated logs and process heartbeats)?',
                true,
            )
        );

        // Without a TTY every confirm() above is skipped and answered "no", so
        // `docker compose exec -T … app:reset` quietly keeps the structure.
        // Saying so beats leaving the user to work out why their labels and
        // accounts are still there.
        $io->listing([
            'mailboxes and labels: ' . ($deleteMailboxes ? 'deleted' : 'kept (--mailboxes)'),
            'contacts: ' . ($deleteContacts ? 'deleted' : 'kept (--contacts)'),
            'accounts and aliases: ' . ($deleteAccounts ? 'deleted' : 'kept (--accounts)'),
            'monitoring data: ' . ($resetMonitoring ? 'cleared' : 'kept'),
        ]);

        $io->section('Truncating tables...');

        $connection = $this->em->getConnection();

        // Disable FK checks while truncating
        $connection->executeStatement('SET session_replication_role = replica');

        $tables = [
            'messenger_messages',
            'message_part',
            'message_label',
            'thread_label',
            'message',
            'message_thread',
            'jmap_change_log',
            'uploaded_blob',
        ];

        if (true === $deleteMailboxes) {
            // Before label: label_binding FKs both, and mailbox is referenced
            // by binding.mailbox_id.
            $tables[] = 'label_binding';
            $tables[] = 'label';
            $tables[] = 'mailbox';
        }

        if (true === $deleteContacts) {
            $tables[] = 'contact';
        }

        if (true === $deleteAccounts) {
            // Every table with an account_id, then the accounts. The user rows
            // stay: dropping those would lock you out of the app you are
            // resetting.
            $tables[] = 'email_alias';
            $tables[] = 'account';
        }

        if (true === $resetMonitoring) {
            $tables[] = 'log_entry';
            $tables[] = 'process_heartbeat';
        }

        // Truncate against what the database actually has, not what this list
        // claims. A table dropped by a later migration would otherwise abort the
        // whole reset mid-way — with FK checks still disabled for the session.
        $existing = $connection->createSchemaManager()->listTableNames();

        foreach ($tables as $table) {
            if (false === in_array($table, $existing, true)) {
                $io->text('– '.$table.' (not in schema, skipped)');

                continue;
            }

            $connection->executeStatement(sprintf('TRUNCATE TABLE %s CASCADE', $table));
            $io->text('✓ '.$table);
        }

        // Clear per-account sync cursors so the next run re-syncs from scratch
        if (false === $deleteAccounts) {
            $connection->executeStatement(<<<'SQL'
                UPDATE account SET
                    gmail_history_id = NULL,
                    graph_delta_links = '{}',
                    last_synced_at = NULL
                SQL);
            $io->text('✓ account (sync cursors)');
        }

        // Kept mailboxes still carry IMAP cursors; without clearing them nothing would be re-fetched
        if (true !== $deleteMailboxes) {
            $connection->executeStatement(<<<'SQL'
                UPDATE mailbox SET
                    uid_validity = NULL,
                    last_seen_uid = NULL,
                    synced_at = NULL
                SQL);
            $io->text('✓ mailbox (sync cursors)');
        }

        // Re-enable FK checks
        $connection->executeStatement('SET session_replication_role = DEFAULT');

        $io->success(true === $deleteAccounts
            ? 'Done. Add an account to start over.'
            : 'Done. Run app:mail:sync to re-sync.');

        return Command::SUCCESS;
    }

    /**
     * Put the install back to the state a fresh `docker compose up` produces:
     * no data, no users, no generated secrets. The next page load offers the
     * create-your-account screen again.
     *
     * Deliberately separate from the flags above rather than another one of
     * them. Those keep you signed in and keep your accounts syncing; this one
     * deletes the user you are running it as, and there is nothing to undo it
     * with.
     */
    private function fullReset(SymfonyStyle $io, bool $interactive): int
    {
        $io->warning([
            'FULL RESET — this deletes everything, not just synced mail:',
            'every user (you included), every account and its stored password,',
            'the files on disk, and the secrets generated on first run.',
            'The install goes back to asking for its first administrator.',
        ]);

        if (true === $interactive && false === $io->confirm('Continue?', false)) {
            $io->text('Nothing was changed.');

            return Command::SUCCESS;
        }

        $connection = $this->em->getConnection();

        // Every table the schema has, rather than a list to keep in step —
        // "everything" is the point, and a table added later must not survive a
        // reset just because nobody remembered to add it here.
        $tables = array_filter(
            $connection->createSchemaManager()->listTableNames(),
            static fn (string $table): bool => 'doctrine_migration_versions' !== $table,
        );

        $io->section('Truncating every table...');

        $connection->executeStatement('SET session_replication_role = replica');

        foreach ($tables as $table) {
            $connection->executeStatement(sprintf('TRUNCATE TABLE %s CASCADE', $table));
        }

        $connection->executeStatement('SET session_replication_role = DEFAULT');

        $io->text(sprintf('✓ %d tables', count($tables)));

        $io->section('Removing stored files...');

        // Attachments, raw messages and uploads are all referenced by rows that
        // no longer exist, so leaving them would be leaking disk to nothing.
        foreach (['var/attachments', 'var/raw', 'var/uploads'] as $directory) {
            $path = $this->projectDir.'/'.$directory;

            if (true === $this->filesystem->exists($path)) {
                $this->filesystem->remove($path);
                $io->text('✓ '.$directory);
            }
        }

        $io->section('Clearing generated secrets...');

        $removed = $this->secrets->remove(self::RESETTABLE_SECRETS);

        $this->filesystem->remove($this->projectDir.'/var/secrets/jwt');

        $io->listing([...$removed, 'JWT keypair']);
        $io->text('POSTGRES_PASSWORD kept — Postgres was initialised with it. To change that, wipe the database volume.');

        $io->success([
            'Done. Restart the stack so every service picks up new secrets:',
            '  docker compose restart',
            'Then open plMail and create the first administrator.',
        ]);

        return Command::SUCCESS;
    }
}
