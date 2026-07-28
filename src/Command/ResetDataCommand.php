<?php

namespace App\Command;

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
            ->addOption('keep-monitoring', null, InputOption::VALUE_NONE, 'Keep monitoring data (aggregated logs and process heartbeats)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->warning('This will permanently delete synced data from the database.');

        $deleteMailboxes = true === $input->getOption('mailboxes') || (
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

        $io->section('Truncating tables...');

        $connection = $this->em->getConnection();

        // Disable FK checks while truncating
        $connection->executeStatement('SET session_replication_role = replica');

        $tables = [
            'messenger_messages',
            'message_part',
            'message_label',
            'thread_label',
            'message_thread_mailbox',
            'message',
            'message_thread',
            'jmap_change_log',
            'uploaded_blob',
        ];

        if (true === $deleteMailboxes) {
            $tables[] = 'label';
            $tables[] = 'mailbox';
        }

        if (true === $deleteContacts) {
            $tables[] = 'contact';
        }

        if (true === $resetMonitoring) {
            $tables[] = 'log_entry';
            $tables[] = 'process_heartbeat';
        }

        foreach ($tables as $table) {
            $connection->executeStatement(sprintf('TRUNCATE TABLE %s CASCADE', $table));
            $io->text('✓ '.$table);
        }

        // Clear per-account sync cursors so the next run re-syncs from scratch
        $connection->executeStatement(<<<'SQL'
            UPDATE account SET
                gmail_history_id = NULL,
                graph_delta_links = '{}',
                last_synced_at = NULL
            SQL);
        $io->text('✓ account (sync cursors)');

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

        $io->success('Done. Run app:mail:sync to re-sync.');

        return Command::SUCCESS;
    }
}
