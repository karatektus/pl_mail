<?php

namespace App\Command;

use App\Repository\AccountRepository;
use App\Service\Imap\MailboxSyncer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:imap:sync-mailboxes',
    description: 'Sync IMAP mailbox structure for one or all accounts',
)]
class SyncMailboxesCommand extends Command
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly MailboxSyncer     $mailboxSyncer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('account-id', InputArgument::OPTIONAL, 'Sync a single account by ID, omit for all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $accountId = $input->getArgument('account-id');

        if ($accountId !== null) {
            $accounts = [$this->accountRepository->find($accountId)];
        } else {
            $accounts = $this->accountRepository->findBy(['isActive' => true]);
        }

        foreach ($accounts as $account) {
            $io->section('Syncing mailboxes for: ' . $account->getLabel());

            try {
                $result = $this->mailboxSyncer->syncForAccount($account);
                $io->success(sprintf(
                    'Created %d, updated %d, deleted %d mailboxes',
                    $result['created'],
                    $result['updated'],
                    $result['deleted'],
                ));
            } catch (\Exception $e) {
                $io->error($e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
