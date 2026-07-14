<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AccountRepository;
use App\Repository\MailboxRepository;
use App\Service\HarvestContactsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:contacts:harvest',
    description: 'Harvest contact addresses from all synced messages',
)]
final class HarvestContactsCommand extends Command
{
    public function __construct(
        private readonly AccountRepository     $accountRepository,
        private readonly MailboxRepository     $mailboxRepository,
        private readonly HarvestContactsService $harvestService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'account-id',
            InputArgument::OPTIONAL,
            'Limit harvest to a single account ID',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $accountId = $input->getArgument('account-id');

        if ($accountId !== null) {
            $accounts = [$this->accountRepository->find((int) $accountId)];
        } else {
            $accounts = $this->accountRepository->findBy(['isActive' => true]);
        }

        foreach ($accounts as $account) {
            if ($account === null) {
                $io->error('Account not found.');
                return Command::FAILURE;
            }

            $io->section(sprintf('Harvesting contacts for %s', $account->getEmail()));

            $mailboxes = $this->mailboxRepository->findBy(['account' => $account]);
            $total     = 0;

            foreach ($mailboxes as $mailbox) {
                $count  = $this->harvestService->harvestForMailbox($mailbox);
                $total += $count;
                $io->text(sprintf('  ✓ %s — %d address occurrences', $mailbox->getName(), $count));
            }

            $io->success(sprintf('Harvested %d address occurrences for %s', $total, $account->getEmail()));
        }

        return Command::SUCCESS;
    }
}
