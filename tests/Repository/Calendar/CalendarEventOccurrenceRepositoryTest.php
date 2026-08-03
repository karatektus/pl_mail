<?php

declare(strict_types=1);

namespace App\Tests\Repository\Calendar;

use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventOccurrenceRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The range read every calendar view makes, against a real Postgres — the only
 * place it can be tested, since the whole point is an operator DQL does not
 * have and an index Doctrine cannot describe.
 *
 * The case that justifies the design is testMultiDayEventStartingBeforeTheWindow.
 * An event that began last week and is still running belongs in this week's
 * view, and it is exactly what a naive `starts_at BETWEEN` misses — silently,
 * and only for the users who have such events.
 */
final class CalendarEventOccurrenceRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventOccurrenceRepository $repository;
    private Calendar $calendar;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(CalendarEventOccurrenceRepository::class);

        $this->connection->beginTransaction();
        $this->calendar = $this->seedCalendar();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnEventInsideTheWindowIsFound(): void
    {
        $this->occurrence('2026-04-15 09:00', '2026-04-15 10:00');

        self::assertCount(1, $this->range('2026-04-13 00:00', '2026-04-20 00:00'));
    }

    /**
     * The one a btree on starts_at gets wrong: it began before the window
     * opened and has not finished. Overlap is the question, not containment.
     */
    public function testMultiDayEventStartingBeforeTheWindow(): void
    {
        $this->occurrence('2026-04-08 09:00', '2026-04-16 17:00');

        self::assertCount(
            1,
            $this->range('2026-04-13 00:00', '2026-04-20 00:00'),
            'a conference running through the week is in the week',
        );
    }

    /** tsrange is half-open, so an event ending exactly at the boundary is out. */
    public function testTheWindowIsHalfOpen(): void
    {
        $this->occurrence('2026-04-12 22:00', '2026-04-13 00:00');
        $this->occurrence('2026-04-19 23:00', '2026-04-20 01:00');

        $found = $this->range('2026-04-13 00:00', '2026-04-20 00:00');

        self::assertCount(1, $found, 'the leading edge is excluded, the trailing one overlaps');
        self::assertSame('2026-04-19 23:00', $found[0]->startsAt->format('Y-m-d H:i'));
    }

    public function testEventsOutsideTheWindowAreNotFound(): void
    {
        $this->occurrence('2026-01-05 09:00', '2026-01-05 10:00');
        $this->occurrence('2026-09-05 09:00', '2026-09-05 10:00');

        self::assertCount(0, $this->range('2026-04-13 00:00', '2026-04-20 00:00'));
    }

    /** A cancelled instance is stored, and hidden unless it is asked for. */
    public function testCancelledOccurrencesAreExcludedByDefault(): void
    {
        $this->occurrence('2026-04-15 09:00', '2026-04-15 10:00', cancelled: true);

        self::assertCount(0, $this->range('2026-04-13 00:00', '2026-04-20 00:00'));
        self::assertCount(1, $this->range('2026-04-13 00:00', '2026-04-20 00:00', includeCancelled: true));
    }

    /** Someone else's calendar is not in the list, so it cannot come back. */
    public function testAnEmptyCalendarListReturnsNothing(): void
    {
        $this->occurrence('2026-04-15 09:00', '2026-04-15 10:00');

        self::assertCount(0, $this->repository->findInRange(
            $this->user,
            [],
            new DateTimeImmutable('2026-04-13 00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-04-20 00:00', new DateTimeZone('UTC')),
        ));
    }

    public function testResultsComeBackInStartOrder(): void
    {
        $this->occurrence('2026-04-17 09:00', '2026-04-17 10:00');
        $this->occurrence('2026-04-14 09:00', '2026-04-14 10:00');
        $this->occurrence('2026-04-16 09:00', '2026-04-16 10:00');

        $starts = array_map(
            static fn (CalendarEventOccurrence $o): string => $o->startsAt->format('Y-m-d'),
            $this->range('2026-04-13 00:00', '2026-04-20 00:00'),
        );

        self::assertSame(['2026-04-14', '2026-04-16', '2026-04-17'], $starts);
    }

    /**
     * "Happening Soon" is a window over the same table filtered on kind, which
     * is what makes a second table for it unnecessary.
     */
    public function testUpcomingExtractedIgnoresHandMadeEvents(): void
    {
        $this->occurrence('2026-04-15 09:00', '2026-04-15 10:00');
        $this->occurrence('2026-04-16 09:00', '2026-04-16 10:00', kind: ExtractionKind::Delivery);

        $found = $this->repository->findUpcomingExtracted(
            $this->user,
            [(int) $this->calendar->id],
            new DateTimeImmutable('2026-04-13 00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-04-20 00:00', new DateTimeZone('UTC')),
        );

        self::assertCount(1, $found);
        self::assertSame(ExtractionKind::Delivery, $found[0]->event->kind);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @return list<CalendarEventOccurrence>
     */
    private function range(string $from, string $to, bool $includeCancelled = false): array
    {
        return $this->repository->findInRange(
            $this->user,
            [(int) $this->calendar->id],
            new DateTimeImmutable($from, new DateTimeZone('UTC')),
            new DateTimeImmutable($to, new DateTimeZone('UTC')),
            $includeCancelled,
        );
    }

    private function occurrence(
        string          $startsAt,
        string          $endsAt,
        bool            $cancelled = false,
        ?ExtractionKind $kind = null,
    ): CalendarEventOccurrence {
        $utc   = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($startsAt, $utc);
        $end   = new DateTimeImmutable($endsAt, $utc);

        $event             = new CalendarEvent();
        $event->calendar   = $this->calendar;
        $event->usr        = $this->user;
        $event->uid        = uniqid('range-', true) . '@plmail.test';
        $event->title      = 'Range fixture';
        $event->startsAt   = $start;
        $event->endsAt     = $end;
        $event->kind       = $kind;
        $event->jscalendar = ['@type' => 'Event', 'title' => 'Range fixture'];
        $this->em->persist($event);

        $occurrence               = new CalendarEventOccurrence();
        $occurrence->event        = $event;
        $occurrence->calendar     = $this->calendar;
        $occurrence->usr          = $this->user;
        $occurrence->recurrenceId = $start;
        $occurrence->startsAt     = $start;
        $occurrence->endsAt       = $end;
        $occurrence->cancelled    = $cancelled;
        $this->em->persist($occurrence);

        $this->em->flush();

        return $occurrence;
    }

    private function seedCalendar(): Calendar
    {
        $user = new User();
        $user->email = 'range-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Range';
        $user->nameLast = 'Fixture';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);
        $this->user = $user;

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->name     = 'Fixture';
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }
}
