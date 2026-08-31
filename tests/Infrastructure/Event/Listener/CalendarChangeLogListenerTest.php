<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Event\Listener;

use App\Domain\Enum\Calendar\CalendarChangeKind;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarChangeLogRepository;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The change log is complete, including the writes that never touch the writer.
 *
 * This is the claim CalendarState's docblock says must hold before a state token
 * can mean anything: "a log that recorded a quarter of the writes would be worse
 * than none… it is a lie with a number on it". Calendars are written from many
 * places and CalendarEvent exposes public properties, so the ones worth pinning
 * are the writes that assign a field and let the flush carry it — an RSVP, a
 * reconciled SEQUENCE, the recurrence flags — none of which pass through
 * CalendarEventWriter. A recorder wired into write() would pass every other test
 * and fail these.
 *
 * Against a real container and a real database rather than doubles: the subject
 * is what Doctrine reports during flush, which is precisely what a double would
 * have to invent.
 */
final class CalendarChangeLogListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventWriter $writer;
    private CalendarChangeLogRepository $log;
    private User $user;
    private Calendar $calendar;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->writer     = $container->get(CalendarEventWriter::class);
        $this->log        = $container->get(CalendarChangeLogRepository::class);

        $this->connection->beginTransaction();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testCreatingAnEventIsRecordedAgainstItsCalendar(): void
    {
        $event = $this->event('Kickoff');

        $rows = $this->rowsForCalendar();

        self::assertCount(1, $rows, 'a created event should leave exactly one row');
        self::assertSame(CalendarChangeKind::Created, $rows[0]->changeKind);
        self::assertSame($event->id, $rows[0]->eventId);
        self::assertSame($event->uid, $rows[0]->eventUid);
    }

    /**
     * The RSVP path. InviteResponder assigns jscalendar and flushes; nothing
     * about that write goes near CalendarEventWriter.
     */
    public function testAnRsvpIsRecordedEvenThoughItSkipsTheWriter(): void
    {
        $event = $this->event('Quarterly review');
        $since = $this->latest();

        $event->jscalendar = ['participants' => ['me@example.test' => ['participationStatus' => 'accepted']]];
        $this->em->flush();

        $rows = $this->rowsForCalendar($since);

        self::assertCount(1, $rows, 'an answer to an invitation is a change a client must see');
        self::assertSame(CalendarChangeKind::Updated, $rows[0]->changeKind);
    }

    /** The EventReconciler path: SEQUENCE is an iCalendar field, so it counts. */
    public function testASequenceBumpIsRecorded(): void
    {
        $event = $this->event('Standup');
        $since = $this->latest();

        ++$event->sequence;
        $this->em->flush();

        self::assertCount(1, $this->rowsForCalendar($since));
    }

    /**
     * Bookkeeping is not a change. Recording it would wake every client each
     * time a sync pass stamped a row it had not altered.
     */
    public function testSyncBookkeepingDoesNotMoveTheTokenRatherThanWakingEveryClient(): void
    {
        $event = $this->event('Retro');
        $since = $this->latest();

        $event->syncedAt = new DateTimeImmutable();
        $event->remoteId = 'remote-42';
        $this->em->flush();

        self::assertSame([], $this->rowsForCalendar($since), 'no client can observe these fields');
        self::assertSame($since, $this->latest(), 'the token must not move');
    }

    /**
     * The tombstone is the only surviving record of a deleted event, so it has
     * to carry what a CalDAV client names the resource by.
     */
    public function testDeletingAnEventLeavesATombstoneThatStillNamesIt(): void
    {
        $event = $this->event('Cancelled thing');
        $uid   = $event->uid;
        $id    = $event->id;
        $since = $this->latest();

        $this->em->remove($event);
        $this->em->flush();

        $rows = $this->rowsForCalendar($since);

        self::assertCount(1, $rows);
        self::assertSame(CalendarChangeKind::Destroyed, $rows[0]->changeKind);
        self::assertSame($uid, $rows[0]->eventUid, 'the uid must outlive the row it came from');
        self::assertSame($id, $rows[0]->eventId);
    }

    /**
     * A move is two facts, not one: the collection it left has to be told the
     * resource is gone, or it goes on advertising an href that answers 404.
     */
    public function testMovingAnEventBetweenCalendarsTellsBothCollectionsRatherThanOnlyTheNewOne(): void
    {
        $event = $this->event('Moving day');
        $since = $this->latest();

        $destination = $this->calendarNamed('Second');

        $event->calendar = $destination;
        $this->em->flush();

        $left    = $this->rowsForCalendar($since);
        $arrived = $this->log->changesSinceForCalendar((int) $destination->id, 0, 50);

        self::assertCount(1, $left);
        self::assertSame(CalendarChangeKind::Destroyed, $left[0]->changeKind, 'the old collection loses it');

        self::assertCount(1, $arrived);
        self::assertSame(CalendarChangeKind::Created, $arrived[0]->changeKind, 'the new collection gains it');
    }

    /** The per-user read spans calendars; the per-calendar read does not. */
    public function testTheUserScopedReadSpansEveryCalendar(): void
    {
        $second = $this->calendarNamed('Second');

        $this->event('On the first');
        $this->event('On the second', $second);

        self::assertCount(2, $this->log->changesSinceForUser((int) $this->user->id, 0, 50));
        self::assertCount(1, $this->log->changesSinceForCalendar((int) $second->id, 0, 50));
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<\App\Entity\Calendar\CalendarChangeLog> */
    private function rowsForCalendar(int $since = 0, ?Calendar $calendar = null): array
    {
        return $this->log->changesSinceForCalendar(
            (int) ($calendar ?? $this->calendar)->id,
            $since,
            50,
        );
    }

    private function latest(): int
    {
        return $this->log->latestSequenceForCalendar((int) $this->calendar->id);
    }

    private function event(string $title, ?Calendar $calendar = null): CalendarEvent
    {
        $event = $this->writer->write(
            new CalendarEvent(),
            $calendar ?? $this->calendar,
            $this->user,
            $title,
            new DateTimeImmutable('2026-09-10 09:00:00'),
            new DateTimeImmutable('2026-09-10 10:00:00'),
            'UTC',
        );

        $this->em->flush();

        return $event;
    }

    private function calendarNamed(string $name): Calendar
    {
        $calendar           = new Calendar();
        $calendar->usr      = $this->user;
        $calendar->name     = $name;
        $calendar->role     = CalendarRole::Custom;
        $calendar->timeZone = 'UTC';

        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'changelog-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Change';
        $user->nameLast  = 'Log';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->name     = 'Change log fixture';
        $calendar->role     = CalendarRole::Custom;
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;
    }
}
