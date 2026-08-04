<?php

declare(strict_types=1);

namespace App\Command\Calendar;

use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Repository\Calendar\CalendarRepository;
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
 * The sweep the scheduler fires, and the one a person runs when a calendar
 * looks stale.
 *
 * Dispatches rather than syncing inline, like app:mail:sync: the work talks to
 * a third party, can be throttled, and has a retry policy that lives on the
 * transport. Running it in the console process would give a hand-run sync
 * different failure behaviour from the scheduled one, which is the difference
 * that makes "it works when I run it by hand" a sentence people say.
 *
 * --stale is what makes it a sweep. Without it every mirrored calendar is
 * dispatched, which is what a person debugging one wants and what a
 * fifteen-minute cron must not do.
 */
#[AsCommand(
    name: 'app:calendar:sync',
    description: 'Dispatch a two-way sync for connected calendars — all of them, one, or only those due',
)]
final class CalendarSyncCommand extends Command
{
    /**
     * How long a calendar may go unsynced before the scheduled sweep picks it
     * up. Slightly under the sweep's own interval, so a run that starts a
     * minute late still finds the calendar it synced last time rather than
     * skipping it for a whole cycle.
     */
    private const string STALE_AFTER = '-14 minutes';

    public function __construct(
        private readonly CalendarRepository  $calendars,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('calendar-id', InputArgument::OPTIONAL, 'Sync a single calendar by ID; omit for all connected ones');
        $this->addOption('stale', null, InputOption::VALUE_NONE, 'Only calendars not synced recently — what the scheduler runs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $calendarId = $input->getArgument('calendar-id');

        if (null !== $calendarId) {
            $calendar = $this->calendars->find((int) $calendarId);

            if (null === $calendar) {
                $io->error(sprintf('Calendar %s not found.', $calendarId));

                return Command::FAILURE;
            }

            if (false === $calendar->isSynced()) {
                $io->error(sprintf('Calendar %s is not connected to a remote.', $calendarId));

                return Command::FAILURE;
            }

            $calendars = [$calendar];
        } else {
            // A far-future cutoff means "everything", which is the same query
            // rather than a second one — two queries would be two definitions
            // of what a syncable calendar is, and they would drift.
            $cutoff = true === $input->getOption('stale')
                ? new DateTimeImmutable(self::STALE_AFTER)
                : new DateTimeImmutable('+1 year');

            $calendars = $this->calendars->findDueForSync($cutoff);
        }

        foreach ($calendars as $calendar) {
            $this->bus->dispatch(new SyncCalendarMessage((int) $calendar->id));
            $io->text(sprintf('→ dispatched sync for %s (#%d)', $calendar->name, (int) $calendar->id));
        }

        $io->success(sprintf('Dispatched %d calendar sync(s).', count($calendars)));

        return Command::SUCCESS;
    }
}
