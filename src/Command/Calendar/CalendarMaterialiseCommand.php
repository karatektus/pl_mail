<?php

declare(strict_types=1);

namespace App\Command\Calendar;

use App\Repository\Calendar\CalendarEventRepository;
use App\Service\Calendar\RecurrenceMaterialiser;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rolls the occurrence horizon forward, so a repeating event does not quietly
 * run out of dates.
 *
 * An event's occurrences are drawn when it is SAVED, out to
 * RecurrenceMaterialiser::HORIZON_FUTURE. Nothing moved that window afterwards.
 * A weekly standup created today reaches two years out; in eighteen months it
 * reaches six, and eventually the last row is in the past — at which point the
 * series stops being drawn on the calendar and its reminders stop firing,
 * because DueAlertReader reads occurrence rows and there are none left. Nothing
 * announces this. The event still exists, still says it repeats weekly, and is
 * simply absent from next month.
 *
 * The repository method this calls has existed since the materialiser did, with
 * a docblock describing exactly this sweep, and nothing ever called it.
 * `CONTRIBUTING.md` and `DueAlertReader` both assert "the nightly sweep that
 * rolls the horizon forward" as though it were running. It is now.
 *
 * **Everything unbounded, plus anything ending after the current horizon** —
 * which is the repository's criterion, and is deliberately not "everything
 * recurring". A series with an UNTIL inside the window is already drawn to its
 * end and re-drawing it every night would be work with no output.
 *
 * Nightly rather than hourly, because the window is measured in years and a day
 * of drift is invisible; and re-materialising is idempotent — it clears the
 * event's occurrences and draws them again from the rule — so a missed night
 * costs nothing and a doubled run costs nothing.
 *
 * Flushed in batches rather than once at the end. An install where this has
 * never run has every recurring event to catch up on at once, and one
 * transaction holding all of them is a lock held for the length of the sweep on
 * the table the calendar reads on every page.
 */
#[AsCommand(
    name: 'app:calendar:materialise',
    description: 'Redraw the occurrences of recurring events whose horizon no longer reaches far enough',
)]
final class CalendarMaterialiseCommand extends Command
{
    /**
     * Events per flush.
     *
     * A recurring event is up to a few hundred occurrence rows, so this is tens
     * of thousands of rows per transaction at the top end — large enough that
     * the per-flush overhead does not dominate, small enough not to hold the
     * table for the whole sweep.
     */
    private const int BATCH = 50;

    public function __construct(
        private readonly CalendarEventRepository $events,
        private readonly RecurrenceMaterialiser  $materialiser,
        private readonly EntityManagerInterface  $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'List the events that would be redrawn without touching anything',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $now    = new DateTimeImmutable();
        $stale  = $this->events->findNeedingHorizonExtension($now->modify(RecurrenceMaterialiser::HORIZON_FUTURE));
        $dryRun = true === $input->getOption('dry-run');

        if ([] === $stale) {
            $io->text('Every recurring event already reaches the horizon.');

            return Command::SUCCESS;
        }

        if (true === $dryRun) {
            foreach ($stale as $event) {
                $io->text(sprintf('→ #%d %s', (int) $event->id, $event->title));
            }

            $io->success(sprintf('%d event(s) would be redrawn.', count($stale)));

            return Command::SUCCESS;
        }

        $done = 0;

        foreach ($stale as $event) {
            $this->materialiser->materialise($event, $now);

            ++$done;

            if (0 === $done % self::BATCH) {
                $this->em->flush();
            }
        }

        $this->em->flush();

        $io->success(sprintf('Redrew %d recurring event(s).', $done));

        return Command::SUCCESS;
    }
}
