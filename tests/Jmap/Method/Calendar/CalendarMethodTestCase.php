<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Service\Calendar\CalendarEventWriter;
use App\Tests\Jmap\JmapTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The world the calendar methods run against: the JMAP fixture's user and mail
 * account, plus calendars and events written the way the application writes
 * them.
 *
 * Events are seeded through CalendarEventWriter rather than by assigning
 * columns, and that is not a convenience — an event with no occurrence rows is
 * an event no calendar view can see, so a fixture that skipped the writer would
 * let a broken query pass by testing against data the application could never
 * produce.
 *
 * Times are relative to now. RecurrenceMaterialiser only writes occurrences
 * inside a horizon around the current instant, so a fixture pinned to a literal
 * date is a suite that starts failing on a particular morning.
 */
abstract class CalendarMethodTestCase extends JmapTestCase
{
    protected CalendarEventWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = self::getContainer()->get(CalendarEventWriter::class);
    }

    /** Midnight UTC in a few days' time, the anchor every fixture hangs off. */
    protected function baseDay(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+3 days')
            ->setTime(0, 0);
    }

    protected function seedCalendar(string $name, bool $isReadOnly = false, ?User $user = null): Calendar
    {
        $calendar = new Calendar();
        $calendar->usr = $user ?? $this->user;
        $calendar->name = $name;
        $calendar->role = CalendarRole::Custom;
        $calendar->timeZone = 'UTC';
        $calendar->isReadOnly = $isReadOnly;

        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }

    /**
     * @param array<string,mixed>|null $recurrenceRule
     */
    protected function seedEvent(
        Calendar $calendar,
        string $title,
        DateTimeImmutable $startsAt,
        string $duration = '+1 hour',
        ?array $recurrenceRule = null,
    ): CalendarEvent {
        $event = new CalendarEvent();
        $event->uid = sprintf('%s-%s@plmail.test', str_replace(' ', '-', strtolower($title)), uniqid('', true));

        $this->writer->write(
            event: $event,
            calendar: $calendar,
            user: $calendar->usr ?? $this->user,
            title: $title,
            startsAt: $startsAt,
            endsAt: $startsAt->modify($duration),
            timeZone: 'UTC',
            recurrenceRule: $recurrenceRule,
        );

        $this->em->flush();

        return $event;
    }

    /**
     * A second user with their own account and calendar — the "somebody else"
     * every isolation assertion here needs.
     */
    protected function otherUser(): User
    {
        $user = new User();
        $user->email = 'calendar-other-'.uniqid('', true).'@example.test';
        $user->nameFirst = 'Other';
        $user->nameLast = 'Person';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * How many occurrence rows an event has, read from the database rather than
     * from the entity's collection — the collection can be populated in memory
     * by the write that just happened, and the claim being made is that the
     * rows exist.
     */
    protected function occurrenceCount(CalendarEvent $event): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM calendar_event_occurrence WHERE event_id = :eventId',
            ['eventId' => (int) $event->id],
        );
    }
}
