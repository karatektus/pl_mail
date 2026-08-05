<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Alert;

use App\Domain\DTO\Calendar\DueAlert;
use App\Domain\Enum\Calendar\AlertAction;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Service\Calendar\Alert\AlertMessageBuilder;
use App\Service\Calendar\Alert\AlertReader;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a reminder actually says.
 *
 * The wording is one class rather than one per channel because the failure it
 * prevents is specific and unreproducible once it ships: a push that says 10:00
 * and a mail that says 09:00 for the same meeting, because each side picked its
 * own zone. Asking once, from the resolver the editor and every calendar view
 * already use, is what makes the two agree by construction.
 *
 * The other claim here is about all-day events. They are floating — local
 * midnight with no zone — so the "00:00" in the column is an artefact of how the
 * instant is stored and not a time anybody chose. Printing it would put a
 * midnight on somebody's birthday reminder.
 *
 * Needs a container because CalendarTimeResolver reads the calendar's own zone,
 * which is a row.
 */
final class AlertMessageBuilderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private AlertMessageBuilder $wording;
    private AlertReader $alerts;
    private User $user;
    private Calendar $calendar;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->wording    = $container->get(AlertMessageBuilder::class);
        $this->alerts     = $container->get(AlertReader::class);

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

    /**
     * The event's own zone, not UTC and not the process's.
     *
     * 07:30 UTC is 09:30 in Berlin, and a reminder that announced the former
     * would be two hours out for the person reading it while looking at a
     * calendar that says the latter.
     */
    public function testTheTimeIsSaidInTheZoneTheEventIsKeptIn(): void
    {
        $due = $this->due('Europe/Berlin', '2026-06-02 07:30:00');

        self::assertStringContainsString('09:30', $this->wording->when($due));
    }

    /** An all-day event has a date and no clock, because it has no clock. */
    public function testAnAllDayEventIsAnnouncedWithNoTimeOfDay(): void
    {
        $due = $this->due(null, '2026-06-02 00:00:00', allDay: true);

        self::assertSame('Tue, 2 Jun 2026', $this->wording->when($due));
    }

    /** Where, when there is a where — it is the second thing a reminder needs. */
    public function testTheLocationIsPartOfTheBodyWhenThereIsOne(): void
    {
        $due = $this->due('UTC', '2026-06-02 09:00:00', location: 'Room 3');

        self::assertStringContainsString('Room 3', $this->wording->body($due));
    }

    /** A notification with a blank title is a blank box on a lock screen. */
    public function testAnEventWithNoTitleStillHasSomethingToShow(): void
    {
        $due = $this->due('UTC', '2026-06-02 09:00:00', title: '');

        self::assertNotSame('', trim($this->wording->title($due)));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function due(
        ?string $zone,
        string  $startsAt,
        bool    $allDay = false,
        ?string $location = null,
        string  $title = 'Standup',
    ): DueAlert {
        $utc   = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($startsAt, $utc);
        $alert = $this->alerts->offsetAlert('-PT10M', AlertAction::Display);

        self::assertNotNull($alert);

        $event           = new CalendarEvent();
        $event->calendar = $this->calendar;
        $event->usr      = $this->user;
        $event->title    = $title;
        $event->location = $location;
        $event->timeZone = $zone;
        $event->isAllDay = $allDay;
        $event->startsAt = $start;
        $event->endsAt   = $start->modify('+30 minutes');

        return new DueAlert(
            event:        $event,
            user:         $this->user,
            eventId:      1,
            userId:       (int) $this->user->id,
            alert:        $alert,
            recurrenceId: $start,
            startsAt:     $start,
            endsAt:       $start->modify('+30 minutes'),
            triggerAt:    $start->modify('-10 minutes'),
        );
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'wording-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Wording';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'Wording fixture';
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = 'UTC';
        $calendar->isDefault = true;
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;
    }
}
