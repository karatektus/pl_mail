<?php

declare(strict_types=1);

namespace App\Command\Imap;

use App\Entity\Mail\Account;
use App\Infrastructure\ImapClient\ImapFolder;
use App\Repository\Mail\AccountRepository;
use App\Service\Imap\ImapConnector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What an account's IMAP server says, for someone debugging it: whether the
 * login works, what it offers, which folders it lists and with which
 * attributes, and where it keeps sent mail.
 *
 * Read-only unless asked. The append test writes a message into the Sent
 * folder, which is not something a diagnostic should do to somebody's mailbox
 * without being told to; it was unconditional while this command drove
 * webklex.
 */
#[AsCommand(name: 'app:imap:test', description: 'Test an IMAP connection and folder listing')]
final class ImapTestCommand extends Command
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly ImapConnector     $connector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('account', 'a', InputOption::VALUE_OPTIONAL, 'Account ID to test (defaults to first active account)')
            ->addOption('append', null, InputOption::VALUE_NONE, 'Also append a test message to the Sent folder');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $accountId = $input->getOption('account');
        $account   = null !== $accountId
            ? $this->accounts->find($accountId)
            : $this->accounts->findOneBy(['isActive' => true]);

        if (false === $account instanceof Account) {
            $io->error('No active account found.');

            return Command::FAILURE;
        }

        $io->title(sprintf('Testing IMAP for: %s (%s)', $account->name, $account->email));

        $io->section('1. Connecting and logging in');

        try {
            $session = $this->connector->session($account);
        } catch (\Throwable $e) {
            $io->error(sprintf('Connection failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf('Logged in to %s.', $session->connection->endpoint->host));
        $io->writeln(sprintf('  Capabilities: %s', implode(' ', $session->connection->capabilities)));

        try {
            $io->section('2. Folders (LIST "" "*")');

            $folders = $session->folders();

            foreach ($folders as $folder) {
                $io->writeln(sprintf(
                    '  path="%s"  name="%s"  delimiter="%s"  %s',
                    $folder->path,
                    $folder->fullName(),
                    $folder->delimiter,
                    implode(' ', $folder->attributes),
                ));
            }

            $io->success(sprintf('%d folders found.', count($folders)));

            $io->section('3. The Sent folder');

            $sent = self::sentFolder($folders);

            if (null === $sent) {
                $io->warning('No folder carries \Sent, and none is named like a Sent folder.');
            } else {
                $io->writeln(sprintf('  "%s" (path="%s")', $sent->fullName(), $sent->path));
            }

            if (true === $input->getOption('append') && null !== $sent) {
                $io->section('4. Appending a test message to it');

                $uid = $session->append(
                    $sent->path,
                    "From: test\r\nTo: test\r\nSubject: IMAP append test\r\n\r\nThis is a test.\r\n",
                    ['\\Seen'],
                    new \DateTimeImmutable(),
                );

                $io->success(null === $uid ? 'Appended; the server did not say under which UID.' : sprintf('Appended as UID %d.', $uid));
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            $session->logout();
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<ImapFolder> $folders
     */
    private static function sentFolder(array $folders): ?ImapFolder
    {
        foreach ($folders as $folder) {
            if (true === $folder->hasAttribute('\\Sent')) {
                return $folder;
            }
        }

        foreach ($folders as $folder) {
            if (true === in_array(mb_strtolower($folder->name()), ['sent', 'sent items', 'sent messages', 'sent mail', 'gesendet'], true)) {
                return $folder;
            }
        }

        return null;
    }
}
