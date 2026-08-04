<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Two calendar connections for the browser suite: one that lists calendars and
 * one that refuses to.
 *
 * The browser suite has to be able to open the subscribe screen and see rows,
 * tick one and get a calendar, and meet a discovery failure — and none of that
 * can involve a network. What makes it possible is that the test container
 * registers a driver whose answers are read off the connection itself
 * (config/services_test.yaml, App\Tests\Support\Calendar\ScriptedCalendarSyncDriver);
 * this command writes those answers. The settings keys are inert everywhere
 * else, because nothing outside the test container reads them.
 *
 * Not a fixture the suite could build through the UI instead. Connecting a
 * CalDAV server through the real form probes it, and a probe is a network call
 * — which is the one thing a browser spec must not depend on.
 *
 * Idempotent, and destructive within its own scope: it removes the connections
 * it made last time and the calendars mirrored from them, so a spec that failed
 * halfway does not leave a half-ticked list for the next run to assert against.
 * Nothing outside those two connections is touched.
 */
#[AsCommand(
    name: 'app:test:seed-calendar-source',
    description: 'Create two scripted calendar connections for the browser suite',
)]
final class SeedCalendarSourceCommand extends Command
{
    use TargetsTestUser;

    /** Matches App\Tests\Support\Calendar\ScriptedCalendarSyncDriver. */
    private const string SETTING_SCRIPTED = 'calendar.scripted';
    private const string SETTING_ERROR = 'calendar.scripted.error';
    private const string SETTING_CALENDARS = 'calendar.scripted.calendars';

    /** The connection that answers. Named so a spec can locate its row. */
    public const string WORKING_NAME = 'E2E calendar server';

    /** The connection that refuses, so the spec can meet the failure path. */
    public const string BROKEN_NAME = 'E2E broken calendar server';

    /**
     * What the failing connection says. Phrased the way a real permanent
     * failure is — the spec asserts the sentence reaches the screen, which is
     * the claim that a refusal renders rather than 500s.
     */
    public const string BROKEN_MESSAGE = 'Reconnect the account and allow calendar access — this connection cannot see any calendars.';

    /**
     * The calendars the working connection offers. Two, and the second is
     * read-only on purpose: "a mirror of somewhere that does not accept writes
     * back" is a state with its own badge and its own rule in the engine, and
     * nothing else in the fixtures produces one.
     *
     * @var list<array<string,bool|string>>
     */
    private const array OFFERED = [
        ['remoteId' => 'e2e-team', 'name' => 'E2E Team calendar', 'color' => '#16a34a', 'primary' => true],
        ['remoteId' => 'e2e-holidays', 'name' => 'E2E Public holidays', 'color' => '#ca8a04', 'readOnly' => true],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository         $userRepository,
        private readonly IntegrationRepository  $integrationRepository,
        private readonly CalendarRepository     $calendarRepository,
        #[Autowire('%kernel.environment%')]
        private readonly string                 $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->configureUserOption();

        // The specs call this at the end of their file. A fixture connection
        // left on the user is not inert: every screen that lists connections
        // lists it, and one of them — the compose picker spec — establishes
        // "nothing is connected" as its own premise before asserting on it.
        $this->addOption(
            'clear',
            null,
            InputOption::VALUE_NONE,
            'Remove the seeded connections and their calendars, seeding nothing',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('app:test:seed-calendar-source must not run in the prod environment.');

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
            $io->success(sprintf('Removed the scripted calendar connections for %s.', $userEmail));

            return Command::SUCCESS;
        }

        $working = $this->connection($user, self::WORKING_NAME);
        $working->setSetting(self::SETTING_CALENDARS, self::OFFERED);

        $broken = $this->connection($user, self::BROKEN_NAME);
        $broken->setSetting(self::SETTING_ERROR, self::BROKEN_MESSAGE);

        $this->entityManager->flush();

        $io->success(sprintf(
            'Seeded "%s" (%d calendars) and "%s" for %s.',
            self::WORKING_NAME,
            count(self::OFFERED),
            self::BROKEN_NAME,
            $userEmail,
        ));

        return Command::SUCCESS;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Last run's connections, and everything mirrored from them.
     *
     * The calendars have to go first and by hand: the join column cascades in
     * the database, but Doctrine holds the Calendar rows in memory from the
     * lookup above and would try to re-persist relations to a row it had
     * already deleted.
     */
    private function removePrevious(User $user): void
    {
        foreach ($this->integrationRepository->findForUserOrdered($user) as $integration) {
            if (false === in_array($integration->name, [self::WORKING_NAME, self::BROKEN_NAME], true)) {
                continue;
            }

            foreach ($this->calendarRepository->findMirroredForIntegration($integration) as $calendar) {
                $this->entityManager->remove($calendar);
            }

            $this->entityManager->remove($integration);
        }

        $this->entityManager->flush();
    }

    private function connection(User $user, string $name): Integration
    {
        $integration = new Integration($user, Provider::CalDav, $name);

        // Never requested, because the scripted driver answers before anything
        // would use it. Set anyway so the row looks like every other CalDAV
        // connection to the settings screen, which renders the address.
        $integration->baseUrl  = 'https://caldav.e2e.invalid/dav';
        $integration->username = 'e2e';
        $integration->secret   = 'e2e-not-a-real-password';
        $integration->setSetting(self::SETTING_SCRIPTED, true);

        $this->entityManager->persist($integration);

        return $integration;
    }
}
