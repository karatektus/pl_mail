<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reset',
    description: 'Truncate all synced message data, optionally including mailbox structure',
)]
class ResetDataCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->warning('This will permanently delete synced data from the database.');

        $deleteMailboxes = $io->confirm(
            'Also delete mailbox structure (folders)? If no, only messages and threads will be cleared.',
            false,
        );

        $io->section('Truncating tables...');

        $connection = $this->em->getConnection();

        // Disable FK checks while truncating
        $connection->executeStatement('SET session_replication_role = replica');

        $connection->executeStatement('TRUNCATE TABLE message_part CASCADE');
        $io->text('✓ message_part');

        $connection->executeStatement('TRUNCATE TABLE message CASCADE');
        $io->text('✓ message');

        $connection->executeStatement('TRUNCATE TABLE message_thread_mailbox CASCADE');
        $io->text('✓ message_thread_mailbox');

        $connection->executeStatement('TRUNCATE TABLE message_thread CASCADE');
        $io->text('✓ message_thread');

        if ($deleteMailboxes) {
            $connection->executeStatement('TRUNCATE TABLE mailbox CASCADE');
            $io->text('✓ mailbox');
        }

        $connection->executeStatement('UPDATE account SET gmail_history_id = NULL');
        $io->text('✓ account');
        // Re-enable FK checks
        $connection->executeStatement('SET session_replication_role = DEFAULT');

        $io->success('Done. Run app:imap:sync-mailboxes and app:imap:sync-messages to re-sync.');

        return Command::SUCCESS;
    }
}
