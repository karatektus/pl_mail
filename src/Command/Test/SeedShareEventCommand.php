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
 * One event with a title and a location worth hiding, for the sharing browser
 * tests.
 *
 * A command rather than a spec driving the event editor, for the reason
 * SeedGridEventsCommand gives about its own fixtures: what is under test is the
 * shared link, and routing the fixture through the dialog would make every
 * assertion about redaction fail whenever the dialog does.
 *
 * **The title and location are options** because the spec asserts on their
 * absence, and a string it does not own could in principle appear in the page
 * for some other reason — a fixture whose value the test chose cannot.
 *
 * **Tomorrow rather than today**, and this is the one difference from the grid
 * fixture. A rolling share window starts at the owner's midnight, so an event
 * seeded at ten this morning is inside it; but a spec run at 23:50 would seed
 * an event that has already been and gone in some zones. Tomorrow is inside
 * every rolling window the sharing form can produce and inside no ambiguity.
 *
 * Idempotent and destructive within its own scope only: every run removes the
 * event it made last time, by title, so a spec that failed halfway leaves
 * nothing behind for the next one to trip over.
 */
#[AsCommand(
    name: 'app:test:seed-share-event',
    description: 'Put one titled, located event on the calendar, for the calendar-sharing browser tests',
)]
final class SeedShareEventCommand extends Command
{
    use TargetsTestUser;

    /** What the spec looks for, when it does not say otherwise. */
    private const string DEFAULT_TITLE = 'E2E Secret Standup';

    private const string DEFAULT_LOCATION = 'E2E Secret Room';

    /** Mid-morning, so the event is inside any working-hours window a spec might use. */
    private const string AT = '10:00';

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

        $this->addOption('title', null, InputOption::VALUE_REQUIRED, 'What the event is called');
        $this->addOption('location', null, InputOption::VALUE_REQUIRED, 'Where the event is');
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Remove the seeded event, seeding nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('app:test:seed-share-event must not run in the prod environment.');

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

        $title    = (string) ($input->getOption('title') ?: self::DEFAULT_TITLE);
        $location = (string) ($input->getOption('location') ?: self::DEFAULT_LOCATION);

        $this->removePrevious($user, $title);

        if (true === $input->getOption('clear')) {
            $io->success(sprintf('Removed "%s" for %s.', $title, $userEmail));

            return Command::SUCCESS;
        }

        // The CALENDAR's wall clock, not UTC — the share page draws against the
        // owner's zone, so a UTC instant would land at the hour the spec expects
        // only on an install running in UTC.
        $zone     = $this->zoneOf($calendar);
        $startsAt = new DateTimeImmutable('tomorrow ' . self::AT, $zone);

        $this->writer->write(
            event:    new CalendarEvent(),
            calendar: $calendar,
            user:     $user,
            title:    $title,
            startsAt: $startsAt,
            endsAt:   $startsAt->modify('+1 hour'),
            timeZone: $zone->getName(),
            location: $location,
        );

        $this->entityManager->flush();

        $io->success(sprintf('Seeded "%s" on "%s" for %s.', $title, $calendar->name, $userEmail));

        return Command::SUCCESS;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Last run's event, by title.
     *
     * By title rather than by emptying a calendar, because this deliberately
     * lands on the user's DEFAULT calendar and clearing that would take their
     * own events with it — the same rule SeedGridEventsCommand follows.
     */
    private function removePrevious(User $user, string $title): void
    {
        foreach ($this->events->findBy(['usr' => $user, 'title' => $title]) as $event) {
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
