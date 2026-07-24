<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AccountRepository;
use App\Service\Mail\ConnectionTester;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:mail:test-connection', description: 'Probe an account\'s IMAP/SMTP settings.')]
final class TestConnectionCommand extends Command
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly ConnectionTester  $tester,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('accountId', InputArgument::REQUIRED, 'Account id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $account = $this->accountRepository->find((int) $input->getArgument('accountId'));

        if (null === $account) {
            $io->error('Account not found.');

            return Command::FAILURE;
        }

        $result = $this->tester->test($account);

        $io->definitionList(
            ['IMAP' => $result->imapTarget],
            [''     => $result->imapMessage],
            ['SMTP' => $result->smtpTarget],
            [' '    => $result->smtpMessage],
        );

        return Command::SUCCESS;
    }
}
