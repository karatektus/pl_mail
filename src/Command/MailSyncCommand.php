<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\SyncAccountMessage;
use App\Repository\AccountRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:mail:sync',
    description: 'Dispatch an account-level sync (IMAP or Gmail) for one or all active accounts',
)]
final class MailSyncCommand extends Command
{
    public function __construct(
        private readonly AccountRepository   $accountRepository,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('account-id', InputArgument::OPTIONAL, 'Sync a single account by ID; omit for all active accounts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $accountId = $input->getArgument('account-id');

        if (null !== $accountId) {
            $account = $this->accountRepository->find((int) $accountId);

            if (null === $account) {
                $io->error(sprintf('Account %s not found.', $accountId));
                return Command::FAILURE;
            }

            $accounts = [$account];
        } else {
            $accounts = $this->accountRepository->findBy(['isActive' => true]);
        }

        foreach ($accounts as $account) {
            $this->bus->dispatch(new SyncAccountMessage($account->getId()));
            $io->text(sprintf('→ dispatched sync for %s (#%d)', $account->getEmail(), $account->getId()));
        }

        $io->success(sprintf('Dispatched %d account sync(s).', count($accounts)));

        return Command::SUCCESS;
    }
}
