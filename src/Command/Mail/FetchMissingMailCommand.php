<?php

declare(strict_types=1);

namespace App\Command\Mail;

use App\Repository\Mail\Account\AccountRepository;
use App\Repository\Mail\Mailbox\MailboxRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fetch the mail an IMAP folder holds that plMail does not: the messages the
 * sync tried five times and then passed over for good (#546).
 *
 * By planning each folder's history import again from the top — see
 * MailboxRepository::reopenImport(), and ImapMailboxImporter, which then asks
 * the server what exists and fetches only the UIDs not stored here. Nothing is
 * fetched from this command: the import starts at the account's next sync and
 * runs in the background like the first one did, with its progress in the
 * topbar.
 *
 * Migration Version20261110210000 did this once for every folder, for the
 * mail lost to the search-vector limit. This is for the next time a message
 * is passed over and its cause has been fixed.
 */
#[AsCommand(
    name: 'app:mail:fetch-missing',
    description: 'Fetch the mail an IMAP folder holds and plMail does not, such as messages the sync gave up on',
)]
final class FetchMissingMailCommand extends Command
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly MailboxRepository $mailboxes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('account-id', InputArgument::OPTIONAL, 'One account by ID; omit for every account');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $accountId = $input->getArgument('account-id');
        $account   = null;

        if (null !== $accountId) {
            $account = $this->accounts->find((int) $accountId);

            if (null === $account) {
                $io->error(sprintf('No account with ID %s.', $accountId));

                return Command::FAILURE;
            }
        }

        $folders = $this->mailboxes->reopenImport($account);

        $io->success(sprintf(
            '%d folder(s) will be looked through for missing mail, starting with the next sync.',
            $folders,
        ));

        return Command::SUCCESS;
    }
}
