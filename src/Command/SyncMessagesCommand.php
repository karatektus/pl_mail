<?php

namespace App\Command;

use App\Domain\Helper\ImapConnectionFactory;
use App\Repository\AccountRepository;
use App\Repository\MailboxRepository;
use App\Service\Imap\MessageSyncer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:imap:sync-messages',
    description: 'Sync IMAP messages for one or all accounts',
)]
class SyncMessagesCommand extends Command
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly MailboxRepository $mailboxRepository,
        private readonly MessageSyncer $messageSyncer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('account-id', InputArgument::OPTIONAL, 'Sync a single account by ID, omit for all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $accountId = $input->getArgument('account-id');

        if ($accountId !== null) {
            $accounts = [$this->accountRepository->find($accountId)];
        } else {
            $accounts = $this->accountRepository->findBy(['isActive' => true]);
        }

        foreach ($accounts as $account) {
            $io->section('Syncing messages for: ' . $account->getLabel());

            try {
                $connection = ImapConnectionFactory::connect($account);
            } catch (\Throwable $e) {
                $io->error('Failed to connect: ' . $e->getMessage());
                continue;
            }

            $mailboxes = $this->mailboxRepository->findBy([
                'account'       => $account,
                'isSyncEnabled' => true,
            ]);

            if (count($mailboxes) === 0) {
                $io->warning('No sync-enabled mailboxes found for this account.');
                continue;
            }

            foreach ($mailboxes as $mailbox) {
                $io->text('→ ' . $mailbox->getFullPath());

                try {
                    $this->messageSyncer->syncMailbox($mailbox, $connection);
                    $io->text('  ✓ done');
                } catch (\Throwable $e) {
                    $io->error(sprintf(
                        'Failed to sync mailbox %s: %s',
                        $mailbox->getFullPath(),
                        $e->getMessage(),
                    ));
                }
            }

            $connection->disconnect();
        }

        $io->success('Sync complete.');

        return Command::SUCCESS;
    }
}
