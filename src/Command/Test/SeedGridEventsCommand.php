<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Repository\Calendar\CalendarRepository;
use App\Repository\User\UserRepository;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The three shapes a time-grid has to draw, for the browser tests that drag
 * them around.
 *
 * A command rather than a spec clicking through the editor, and that is a
 * deliberate reversal of what calendar.spec.ts does. What is under test is the
 * grid — where a block lands, what a drag does to it, whether a recurring one
 * stops to ask — and routing every fixture through the event dialog makes each
 * of those cases fail whenever the dialog does, for reasons that have nothing
 * to do with the grid. The three events here are exactly the three axes the
 * grid branches on and nothing else: a plain timed event, an all-day one that
 * has to be lifted out of the hours, and a series whose occurrences raise the
 * this-one-or-all question.
 *
 * **Times are the CALENDAR's wall clock, not UTC**, and the events are seeded
 * on the user's default calendar rather than one of this fixture's own. Both
 * follow from what the grid reads: it draws against the default calendar's zone
 * (CalendarRangeReader::zoneOf), so a fixture written as a UTC instant would sit
 * at the hour the spec expects only on an install running in UTC, and one on a
 * calendar of its own would draw in that calendar's zone instead.
 *
 * "Today" rather than "tomorrow", so a spec can reach the events at
 * /calendar/day with no date in it and the two cannot disagree about which day
 * that is. Late in the working day rather than at 09:00, so a drag downwards
 * has hours of column left below it.
 *
 * Idempotent, and destructive within its own scope only: every run removes what
 * the last one made, by title, so a spec that failed halfway leaves nothing for
 * the next one to count.
 */
#[AsCommand(
    name: 'app:test:seed-grid-events',
    description: 'Put a timed, an all-day and a repeating event on the calendar, for the time-grid browser tests',
)]
final class SeedGridEventsCommand extends Command
{
    use TargetsTestUser;

    /** Named so a spec can find each block by the title in it. */
    public const string TIMED   = 'E2E grid timed';
    public const string ALL_DAY = 'E2E grid all day';
    public const string DAILY   = 'E2E grid daily';

    /**
     * When the timed event starts, on the calendar's own clock.
     *
     * 10:00 because the grid opens scrolled to 07:00 and a block has to be
     * visible without a spec scrolling first, and because a drag two hours down
     * from here is still inside the day.
     */
    private const string TIMED_AT = '10:00';

    /** Far enough below TIMED_AT that the two never share a lane. */
    private const string DAILY_AT = '14:00';

    /**
     * How many days the series runs. Five covers a week view whichever day the
     * suite runs on, without filling the month grid for anything else.
     */
    private const int DAILY_COUNT = 5;

    public function __construct(
        private readonly EntityManagerInterface  $entityManager,
        private readonly UserRepository          $userRepository,
        private readonly CalendarRepository      $calendarRepository,
        private readonly CalendarEventRepository $events,
        private readonly CalendarEventWriter     $writer,
        #[Autowire('%kernel.environment%')]
        private readonly string                  $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->configureUserOption();

        $this->addOption(
            'clear',
            null,
            InputOption::VALUE_NONE,
            'Remove the seeded events, seeding nothing',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('app:test:seed-grid-events must not run in the prod environment.');

            return Command::FAILURE;
        }

        $userEmail = $this->resolveUserEmail($input);
        $user      = $this->userRepository->findOneBy(['email' => $userEmail]);

        if (null === $user) {
            $io->error(sprintf('No user %s — run app:test:seed-user first.', $userEmail));

            return Command::FAILURE;
        }

        $calendar = $this->calendarRepository->findDefaultForUser($user);

        if (null === $calendar) {
            $io->error(sprintf('%s has no default calendar to seed onto.', $userEmail));

            return Command::FAILURE;
        }

        $this->removePrevious($user);

        if (true === $input->getOption('clear')) {
            $io->success(sprintf('Removed the grid fixtures for %s.', $userEmail));

            return Command::SUCCESS;
        }

        $zone  = $this->zoneOf($calendar);
        $timed = new DateTimeImmutable('today ' . self::TIMED_AT, $zone);
        $daily = new DateTimeImmutable('today ' . self::DAILY_AT, $zone);

        $this->write($user, $calendar, self::TIMED, $timed, $timed->modify('+1 hour'), $zone);

        // Midnight to midnight, which is what an all-day event is stored as.
        // The flag is what lifts it out of the hours; the times are only what
        // makes it land on the right day.
        $this->write(
            $user,
            $calendar,
            self::ALL_DAY,
            $timed->setTime(0, 0),
            $timed->setTime(0, 0)->modify('+1 day'),
            $zone,
            isAllDay: true,
        );

        $this->write(
            $user,
            $calendar,
            self::DAILY,
            $daily,
            $daily->modify('+1 hour'),
            $zone,
            recurrenceRule: ['@type' => 'RecurrenceRule', 'frequency' => 'daily', 'count' => self::DAILY_COUNT],
        );

        $this->entityManager->flush();

        $io->success(sprintf('Seeded the grid fixtures on "%s" for %s.', $calendar->name, $userEmail));

        return Command::SUCCESS;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /** @param array<string,mixed>|null $recurrenceRule */
    private function write(
        User              $user,
        Calendar          $calendar,
        string            $title,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        DateTimeZone      $zone,
        bool              $isAllDay = false,
        ?array            $recurrenceRule = null,
    ): void {
        $this->writer->write(
            event:          new CalendarEvent(),
            calendar:       $calendar,
            user:           $user,
            title:          $title,
            startsAt:       $startsAt,
            endsAt:         $endsAt,
            timeZone:       $zone->getName(),
            isAllDay:       $isAllDay,
            recurrenceRule: $recurrenceRule,
        );
    }

    /**
     * Last run's events, by title.
     *
     * By title rather than by clearing a calendar of this fixture's own,
     * because these deliberately live on the user's DEFAULT calendar — see the
     * class docblock — and emptying that would take the user's own events with
     * it. Removing the events takes their occurrences by cascade.
     */
    private function removePrevious(User $user): void
    {
        $titles = [self::TIMED, self::ALL_DAY, self::DAILY];

        foreach ($this->events->findBy(['usr' => $user]) as $event) {
            if (false === in_array((string) $event->title, $titles, true)) {
                continue;
            }

            $this->entityManager->remove($event);
        }

        $this->entityManager->flush();
    }

    private function zoneOf(Calendar $calendar): DateTimeZone
    {
        try {
            return new DateTimeZone($calendar->timeZone);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
