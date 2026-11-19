<?php

declare(strict_types=1);

namespace App\Tests\Repository\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\SyncState;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEvent\CalendarEventColorRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The editor's "Often used" row (#616), counted off the user's own events.
 *
 * The claims: the colour on most events comes first; two spellings of one
 * name are one colour; and a colour only on somebody else's events, or only on
 * an event being deleted, is not this user's.
 */
final class CalendarEventColorRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventColorRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(CalendarEventColorRepository::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheColourOnMostEventsComesFirstWhateverItsSpelling(): void
    {
        [$user, $calendar] = $this->seed();

        $this->event($user, $calendar, 'teal');
        $this->event($user, $calendar, 'orchid');
        $this->event($user, $calendar, 'Orchid ');
        $this->event($user, $calendar, 'orchid');
        $this->event($user, $calendar, null);
        $this->em->flush();

        self::assertSame(['orchid', 'teal'], $this->repository->mostUsed((int) $user->id, 6));
    }

    public function testOtherPeoplesColoursAndDeletedEventsDoNotCount(): void
    {
        [$user, $calendar]   = $this->seed();
        [$other, $theirs]    = $this->seed();

        $this->event($user, $calendar, 'teal');
        $this->event($user, $calendar, 'crimson', SyncState::PendingDelete);
        $this->event($other, $theirs, 'gold');
        $this->em->flush();

        self::assertSame(['teal'], $this->repository->mostUsed((int) $user->id, 6));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function event(User $user, Calendar $calendar, ?string $color, SyncState $state = SyncState::Clean): void
    {
        $utc = new DateTimeZone('UTC');

        $event             = new CalendarEvent();
        $event->usr        = $user;
        $event->calendar   = $calendar;
        $event->uid        = uniqid('colour-', true);
        $event->title      = 'Event';
        $event->startsAt   = new DateTimeImmutable('2026-06-02 09:00', $utc);
        $event->endsAt     = new DateTimeImmutable('2026-06-02 10:00', $utc);
        $event->syncState  = $state;
        $event->jscalendar = null === $color ? ['@type' => 'Event'] : ['@type' => 'Event', 'color' => $color];

        $this->em->persist($event);
    }

    /**
     * @return array{User, Calendar}
     */
    private function seed(): array
    {
        $user            = new User();
        $user->email     = 'colour-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Colour';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->name     = 'Colour fixture';
        $calendar->role     = CalendarRole::Custom;
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);

        $this->em->flush();

        return [$user, $calendar];
    }
}
