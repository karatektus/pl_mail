<?php

declare(strict_types=1);

namespace App\Command\AddressBook;

use App\Infrastructure\Messaging\Message\SyncAddressBookMessage;
use App\Repository\AddressBook\AddressBookRepository;
use App\Service\Demo\DemoMode;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The sweep the scheduler fires for CardDAV address books, and the one a
 * person runs when their contacts look stale — app:calendar:sync's twin.
 *
 * Dispatches rather than syncing inline, for the reason that command gives:
 * a hand-run sync must fail the way the scheduled one does.
 */
#[AsCommand(
    name: 'app:contacts:sync',
    description: 'Dispatch a two-way sync for mirrored CardDAV address books — all of them, one, or only those due',
)]
final class ContactsSyncCommand extends Command
{
    /** Slightly under the sweep's interval — see CalendarSyncCommand::STALE_AFTER. */
    private const string STALE_AFTER = '-14 minutes';

    public function __construct(
        private readonly AddressBookRepository $books,
        private readonly MessageBusInterface   $bus,
        private readonly DemoMode              $demoMode,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('address-book-id', InputArgument::OPTIONAL, 'Sync a single address book by ID; omit for all of them');
        $this->addOption('stale', null, InputOption::VALUE_NONE, 'Only address books not synced recently — what the scheduler runs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (true === $this->demoMode->isEnabled()) {
            $io->note('Demo mode is on — contacts sync is disabled.');

            return Command::SUCCESS;
        }

        $id = $input->getArgument('address-book-id');

        if (null !== $id) {
            $book = $this->books->find((int) $id);

            if (null === $book) {
                $io->error(sprintf('Address book %s not found.', $id));

                return Command::FAILURE;
            }

            $books = [$book];
        } else {
            $cutoff = true === $input->getOption('stale')
                ? new DateTimeImmutable(self::STALE_AFTER)
                : new DateTimeImmutable('+1 year');

            $books = $this->books->findDueForSync($cutoff);
        }

        foreach ($books as $book) {
            $this->bus->dispatch(new SyncAddressBookMessage((int) $book->id));
            $io->text(sprintf('→ dispatched sync for %s (#%d)', $book->name, (int) $book->id));
        }

        $io->success(sprintf('Dispatched %d address book sync(s).', count($books)));

        return Command::SUCCESS;
    }
}
