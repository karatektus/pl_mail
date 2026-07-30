<?php

namespace App\Command\Maintenance;

use Doctrine\ORM\EntityManagerInterface;
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
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mailboxes', null, InputOption::VALUE_NONE, 'Also delete mailbox structure (folders and labels)')
            ->addOption('contacts', null, InputOption::VALUE_NONE, 'Also delete harvested contacts')
            ->addOption('accounts', null, InputOption::VALUE_NONE, 'Also delete the accounts themselves, aliases included (implies --mailboxes)')
            ->addOption('keep-monitoring', null, InputOption::VALUE_NONE, 'Keep monitoring data (aggregated logs and process heartbeats)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

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
}
