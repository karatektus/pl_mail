<?php

declare(strict_types=1);

namespace App\Tests\Command\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventOccurrenceRepository;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\RecurrenceMaterialiser;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A repeating event does not run out of dates.
 *
 * Occurrences are drawn when an event is SAVED, out to the materialiser's
 * future horizon, and until this command existed nothing moved that window
 * again. The failure is entirely silent and takes years to appear: a weekly
 * standup created today is drawn two years out, and in eighteen months reaches
 * six. Eventually the last row is in the past, and from then on the series is
 * absent from the calendar and its reminders never fire — because DueAlertReader
 * reads occurrence rows and there are none left. Nothing logs anything. The
 * event still exists and still says it repeats weekly.
 *
 * The repository method that finds these events was written alongside the
 * materialiser, documented as exactly this sweep, and never called by anything.
 * Both CONTRIBUTING.md and DueAlertReader's docblock referred to "the nightly
 * sweep that rolls the horizon forward" as though it were running.
 *
 * The horizon is simulated by materialising against a `now` in the past rather
 * than by waiting eighteen months or by editing occurrence rows directly: it is
 * the same code path a real install takes, and it is the only way to get an
 * event that is genuinely under-drawn rather than one that has been vandalised
 * into looking that way.
 *
 * Against the real container and database, in a transaction that is rolled
 * back, like every other test here.
 */
final class CalendarMaterialiseCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventWriter $writer;
    private RecurrenceMaterialiser $materialiser;
    private CalendarEventOccurrenceRepository $occurrences;
    private CommandTester $command;
    private User $user;
    private Calendar $calendar;

    protected function setUp(): void
    {
        self::bootKernel();

        $container          = self::getContainer();
        $this->em           = $container->get(EntityManagerInterface::class);
        $this->connection   = $container->get(Connection::class);
        $this->writer       = $container->get(CalendarEventWriter::class);
        $this->materialiser = $container->get(RecurrenceMaterialiser::class);
        $this->occurrences  = $container->get(CalendarEventOccurrenceRepository::class);

        $this->command = new CommandTester(
            new Application(self::$kernel)->find('app:calendar:materialise'),
        );

        $this->connection->beginTransaction();

        $this->user            = new User();
        $this->user->email     = 'horizon-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Horizon';
        $this->user->nameLast  = 'Fixture';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';

        $this->calendar           = new Calendar();
        $this->calendar->usr      = $this->user;
        $this->calendar->name     = 'Horizon fixture';
        $this->calendar->role     = CalendarRole::Custom;
        $this->calendar->timeZone = 'UTC';

        $this->em->persist($this->user);
        $this->em->persist($this->calendar);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The claim, stated as the symptom: a series drawn long ago has dates in the
     * coming year again after the sweep, and did not before it.
     */
    public function testASeriesDrawnLongAgoGetsItsFutureDatesBack(): void
    {
        $event = $this->weeklySeriesDrawnAt('-18 months');

        self::assertSame(
            0,
            $this->occurrencesAfter('+18 months'),
            'the fixture is only meaningful if the series has genuinely run short',
        );

        $this->command->execute([]);

        self::assertGreaterThan(
            0,
            $this->occurrencesAfter('+18 months'),
            'the sweep must draw the dates the original save could not reach',
        );
    }

    /**
     * An unbounded series is the case that can never be finished, so it is the
     * one the sweep exists for.
     */
    public function testAnUnboundedSeriesIsAlwaysACandidate(): void
    {
        $this->weeklySeriesDrawnAt('-18 months');

        $this->command->execute(['--dry-run' => true]);

        self::assertStringContainsString(
            'Standup',
            $this->command->getDisplay(),
            'a rule with no UNTIL can always be drawn further',
        );
    }

    /**
     * A series that has already ended is not redrawn every night for ever.
     *
     * The repository asks for events that are unbounded OR end after the
     * horizon, which is the whole reason it is a QueryBuilder rather than a
     * findBy — and a sweep that ignored the distinction would rewrite every
     * finished series in the install nightly, producing no rows anybody did not
     * already have.
     */
    public function testASeriesThatHasAlreadyEndedIsLeftAlone(): void
    {
        $this->weeklySeriesDrawnAt('-18 months', until: new DateTimeImmutable('-12 months'));

        $this->command->execute(['--dry-run' => true]);

        self::assertStringNotContainsString(
            'Standup',
            $this->command->getDisplay(),
            'its last occurrence is in the past and no rule can produce another',
        );
    }

    /** Running it twice is running it once, so a missed night is not a decision. */
    public function testASecondRunChangesNothing(): void
    {
        $this->weeklySeriesDrawnAt('-18 months');

        $this->command->execute([]);
        $after = $this->occurrencesAfter('+18 months');

        $this->command->execute([]);

        self::assertSame($after, $this->occurrencesAfter('+18 months'), 'redrawing is idempotent');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A weekly series whose occurrences were drawn as they would have been that
     * long ago — which is what an event nobody has edited since actually looks
     * like.
     */
    private function weeklySeriesDrawnAt(string $ago, ?DateTimeImmutable $until = null): CalendarEvent
    {
        $utc   = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($ago, $utc)->setTime(9, 0);

        $rule = ['@type' => 'RecurrenceRule', 'frequency' => 'weekly'];

        if (null !== $until) {
            $rule['until'] = $until->format('Y-m-d\TH:i:s');
        }

        $event = $this->writer->write(
            event:          new CalendarEvent(),
            calendar:       $this->calendar,
            user:           $this->user,
            title:          'Standup',
            startsAt:       $start,
            endsAt:         $start->modify('+30 minutes'),
            timeZone:       'UTC',
            recurrenceRule: $rule,
        );

        $this->em->flush();

        // Redrawn as of that date, so the rows stop where they would have
        // stopped. write() has already materialised against today.
        $this->materialiser->materialise($event, $start);
        $this->em->flush();

        return $event;
    }

    private function occurrencesAfter(string $when): int
    {
        $from = new DateTimeImmutable($when, new DateTimeZone('UTC'));

        return count($this->occurrences->findInRange(
            $this->user,
            [(int) $this->calendar->id],
            $from,
            $from->modify('+2 months'),
        ));
    }
}
