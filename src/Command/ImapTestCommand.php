<?php

namespace App\Command;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Account;
use App\Repository\AccountRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:imap:test', description: 'Test IMAP connection and folder listing')]
class ImapTestCommand extends Command
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly ImapConnectionFactory $imapConnectionFactory,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('account', 'a', InputOption::VALUE_OPTIONAL, 'Account ID to test (defaults to first active account)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $accountId = $input->getOption('account');
        $account = $accountId
            ? $this->accounts->find($accountId)
            : $this->accounts->findOneBy(['isActive' => true]);

        if (!$account instanceof Account) {
            $io->error('No active account found.');
            return Command::FAILURE;
        }

        $io->title(sprintf('Testing IMAP for: %s (%s)', $account->getName(), $account->getEmail()));

// 1. Connect
        $io->section('1. Connecting…');
        try {
            $client = $this->imapConnectionFactory->connect($account);
            $io->success('Connected.');
        } catch (\Throwable $e) {
            $io->error('Connection failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

// 2. List all folders flat
        $io->section('2. All folders (getFolders(false) — flat)');
        try {
            $folders = $client->getFolders(false);
            foreach ($folders as $folder) {
                $io->writeln(sprintf('  path="%s"  name="%s"  delimiter="%s"',
                    $folder->path,
                    $folder->name,
                    $folder->delimiter ?? '/',
                ));
            }
            $io->success(sprintf('%d folders found.', count($folders)));
        } catch (\Throwable $e) {
            $io->error('getFolders(false) failed: ' . $e->getMessage());
        }

// 3. List all folders hierarchical
        $io->section('3. All folders (getFolders(true) — hierarchical)');
        try {
            $folders = $client->getFolders(true);
            $this->printFolderTree($folders, $io);
        } catch (\Throwable $e) {
            $io->error('getFolders(true) failed: ' . $e->getMessage());
        }

// 4. Try getFolder() with common Sent folder names
        $io->section('4. Probing common Sent folder names via getFolder()');
        $candidates = ['Sent', 'Sent Items', 'Sent Messages', 'INBOX.Sent', '[Gmail]/Sent Mail'];
        foreach ($candidates as $candidate) {
            try {
                $folder = $client->getFolder($candidate);
                $io->writeln(sprintf('  <info>✓ Found:</info> "%s" → path="%s"', $candidate, $folder->path));
            } catch (\Throwable) {
                $io->writeln(sprintf('  <comment>✗ Not found:</comment> "%s"', $candidate));
            }
        }

// 5. Try appendMessage to the first successful candidate
        $io->section('5. Testing appendMessage on first working Sent folder');
        foreach ($candidates as $candidate) {
            try {
                $folder = $client->getFolder($candidate);
                $folder->appendMessage(
                    "From: test\r\nTo: test\r\nSubject: IMAP append test\r\n\r\nThis is a test.",
                    '\\Seen',
                    new \DateTimeImmutable(),
                );
                $io->success(sprintf('appendMessage succeeded on "%s"', $candidate));
                break;
            } catch (\Throwable $e) {
                $io->writeln(sprintf('  <comment>✗ appendMessage failed on "%s": %s</comment>', $candidate, $e->getMessage()));
            }
        }

        $client->disconnect();
        $io->success('Done.');

        return Command::SUCCESS;
    }

    private function printFolderTree(iterable $folders, SymfonyStyle $io, int $depth = 0): void
    {
        foreach ($folders as $folder) {
            $io->writeln(sprintf('%s<info>%s</info>  (path="%s")',
                str_repeat('  ', $depth),
                $folder->name,
                $folder->path,
            ));
            if (!empty($folder->children)) {
                $this->printFolderTree($folder->children, $io, $depth + 1);
            }
        }
    }
}
