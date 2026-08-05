<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
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
 * One meeting on two calendars, under one UID — the duplicate the calendar
 * merges into a single chip.
 *
 * Not a fixture the browser suite could build through the UI instead, and that
 * is the whole reason this command exists. A UID is minted server-side and the
 * editor has no field for it, so nothing a user can click produces two rows
 * that share one — which is precisely the state that arises on its own when an
 * invitation is extracted from mail onto the account's calendar while the
 * provider auto-adds the same meeting to a calendar plMail mirrors.
 *
 * The second calendar can be made read-only, because "a mirror of somewhere
 * that does not accept writes back" is a state with its own rule in the editor
 * — listed, disabled, never written — and nothing else in the fixtures produces
 * one on a calendar holding a duplicated meeting. It can also be left empty
 * (--single), which is the fixture for the other direction: the editor offers
 * every calendar, so a spec needs one the meeting is NOT on to tick.
 *
 * Idempotent, and destructive within its own scope: it removes the two
 * calendars it made last time and everything on them, so a spec that failed
 * halfway does not leave a stale chip for the next run to count. Nothing
 * outside those two calendars is touched.
 *
 * Tomorrow rather than a literal date: occurrences are materialised only inside
 * a horizon around now, and the agenda view the specs count chips in is thirty
 * days from today.
 */
#[AsCommand(
    name: 'app:test:seed-duplicate-event',
    description: 'Put one meeting on two calendars under a single UID, for the browser suite',
)]
final class SeedDuplicateEventCommand extends Command
{
    use TargetsTestUser;

    /** The two calendars, named so a spec can locate their checkboxes. */
    public const string FIRST_CALENDAR = 'E2E Duplicate A';
    public const string SECOND_CALENDAR = 'E2E Duplicate B';

    /** What both copies are called, so a spec can locate the chip by its name. */
    public const string TITLE = 'E2E duplicated meeting';

    /**
     * The organiser's UID, shared by both rows. Fixed rather than generated:
     * the identity is the point of the fixture, and a re-run has to land on the
     * same meeting rather than beside it.
     */
    private const string UID = 'e2e-duplicate@organiser.test';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository         $userRepository,
        private readonly CalendarRepository     $calendarRepository,
        private readonly CalendarEventWriter    $writer,
        #[Autowire('%kernel.environment%')]
        private readonly string                 $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->configureUserOption();

        $this->addOption(
            'read-only',
            null,
            InputOption::VALUE_NONE,
            'Make the second calendar one that accepts no writes back',
        );

        $this->addOption(
            'single',
            null,
            InputOption::VALUE_NONE,
            'Put the meeting on the first calendar only, leaving the second empty',
        );

        $this->addOption(
            'clear',
            null,
            InputOption::VALUE_NONE,
            'Remove the seeded calendars and their events, seeding nothing',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('app:test:seed-duplicate-event must not run in the prod environment.');

            return Command::FAILURE;
        }

        $userEmail = $this->resolveUserEmail($input);
        $user      = $this->userRepository->findOneBy(['email' => $userEmail]);

        if (false === $user instanceof User) {
            $io->error(sprintf('Test user "%s" not found — run app:test:seed-user first.', $userEmail));

            return Command::FAILURE;
        }

        $this->removePrevious($user);

        if (true === $input->getOption('clear')) {
            $io->success(sprintf('Removed the duplicated meeting for %s.', $userEmail));

            return Command::SUCCESS;
        }

        $first  = $this->calendar($user, self::FIRST_CALENDAR, '#2563eb');
        $second = $this->calendar($user, self::SECOND_CALENDAR, '#16a34a');

        $second->isReadOnly = true === $input->getOption('read-only');

        $this->entityManager->flush();

        $startsAt = new DateTimeImmutable('tomorrow 09:00', new DateTimeZone('UTC'));

        // --single is the fixture for the other half of the editor: a meeting on
        // one calendar with a second one standing empty beside it, so a spec can
        // tick that empty one and check what lands there. Both calendars are
        // still made, because the empty destination is the point.
        $calendars = true === $input->getOption('single') ? [$first] : [$first, $second];

        foreach ($calendars as $calendar) {
            $event      = new CalendarEvent();
            $event->uid = self::UID;

            $this->writer->write(
                event:    $event,
                calendar: $calendar,
                user:     $user,
                title:    self::TITLE,
                startsAt: $startsAt,
                endsAt:   $startsAt->modify('+1 hour'),
                timeZone: 'UTC',
            );
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Seeded "%s" on %s%s for %s.',
            self::TITLE,
            1 === count($calendars)
                ? sprintf('"%s", with "%s" left empty', self::FIRST_CALENDAR, self::SECOND_CALENDAR)
                : sprintf('"%s" and "%s"', self::FIRST_CALENDAR, self::SECOND_CALENDAR),
            true === $second->isReadOnly ? ' (read-only)' : '',
            $userEmail,
        ));

        return Command::SUCCESS;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Last run's calendars, and everything on them.
     *
     * The events go by cascade at the database, but Doctrine has to be told
     * about the calendars themselves rather than the rows underneath: removing
     * a Calendar it holds in memory is what makes the identity map agree with
     * what the delete actually did.
     */
    private function removePrevious(User $user): void
    {
        foreach ($this->calendarRepository->findForUser($user) as $calendar) {
            if (false === in_array($calendar->name, [self::FIRST_CALENDAR, self::SECOND_CALENDAR], true)) {
                continue;
            }

            $this->entityManager->remove($calendar);
        }

        $this->entityManager->flush();
    }

    private function calendar(User $user, string $name, string $color): Calendar
    {
        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->name     = $name;
        $calendar->color    = $color;
        $calendar->role     = CalendarRole::Custom;
        $calendar->timeZone = 'UTC';

        $this->entityManager->persist($calendar);

        return $calendar;
    }
}
