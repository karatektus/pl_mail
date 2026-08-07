<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Alert;

use App\Domain\Enum\Calendar\AlertAction;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\EventStatus;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Service\Calendar\Alert\AlertReader;
use App\Service\Calendar\Alert\DueAlertReader;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What is due, and — more importantly — what is not.
 *
 * Three claims, each of which is a way this feature goes wrong in production
 * rather than in review:
 *
 *   **A backfill must not fire the past.** Turning alerts on, or running
 *   `app:backfill events` over a year of stored flight confirmations, creates
 *   hundreds of occurrences whose alerts have never been delivered and whose
 *   triggers are all in the past. There is no delivery record to suppress them,
 *   because the records only begin to exist once the sweep does. The floor is
 *   what suppresses them, and it needs no state: a trigger older than the
 *   lookback window is not due and never will be.
 *
 *   **A recurring event means one alert per occurrence.** The candidates are
 *   occurrence rows rather than events, so this falls out — but it falls out
 *   only as long as nothing here re-reads the recurrence rule, which is what a
 *   later "optimisation" would do.
 *
 *   **An occurrence somebody moved alerts about where it went.** The override is
 *   already applied in the occurrence row, so reading the row is what makes this
 *   right. A reader that computed the trigger from the series' own start would
 *   announce a standup for 09:00 that was dragged to 15:00, which is worse than
 *   no reminder because it is believed.
 *
 * Against a real container and a real database rather than doubles. Every
 * collaborator is final, so nothing can be doubled anyway; and the behaviour
 * worth pinning here emerges from the materialiser, the jsonb query and the
 * arithmetic together, which a set of mocks would assert into existence rather
 * than observe.
 *
 * "Nothing is due" is asserted as `assertCount(0, …)` rather than as
 * `assertSame([], …)`, which looks like the weaker claim and is not: a DueAlert
 * holds the CalendarEvent, which holds a thousand materialised occurrences, each
 * of which holds the event again. PHPUnit's exporter walks that graph to build
 * the failure message, so a failing assertSame here does not report a failure —
 * it hangs, and the suite has to be killed. The count says the same thing and
 * says it in a sentence.
 */
final class DueAlertReaderTest extends KernelTestCase
{
    /** The instant every case below is measured against — a Tuesday, mid-morning. */
    private const string NOW = '2026-06-02 09:00:00';

    private EntityManagerInterface $em;
    private Connection $connection;
    private DueAlertReader $due;
    private CalendarEventWriter $writer;
    private AlertReader $alerts;
    private User $user;
    private Calendar $calendar;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->due        = $container->get(DueAlertReader::class);
        $this->writer     = $container->get(CalendarEventWriter::class);
        $this->alerts     = $container->get(AlertReader::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
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

    /** The ordinary case: a ten-minute alert on a meeting ten minutes away. */
    public function testAnAlertIsDueWhenItsTriggerHasJustPassed(): void
    {
        $this->eventAt('2026-06-02 09:10:00', ['-PT10M']);

        $due = $this->due->due($this->now());

        self::assertCount(1, $due);
        self::assertSame('2026-06-02 09:00:00', $due[0]->triggerAt->format('Y-m-d H:i:s'));
    }

    /** A trigger that has not arrived is not due, however close it is. */
    public function testATriggerStillInTheFutureIsNotDue(): void
    {
        $this->eventAt('2026-06-02 09:11:00', ['-PT10M']);

        self::assertCount(0, $this->due->due($this->now()));
    }

    /**
     * The floor. This is the backfill case: an event whose alert was due
     * yesterday must not be announced today.
     */
    public function testAnAlertWhoseTriggerIsOlderThanTheLookbackIsNeverDue(): void
    {
        $this->eventAt('2026-06-01 09:10:00', ['-PT10M']);

        self::assertCount(
            0,
            $this->due->due($this->now()),
            'a day-old reminder must not be delivered when the sweep first runs',
        );
    }

    /**
     * Inside the window it still is, so a worker that was down for a few minutes
     * catches up rather than dropping what it missed.
     */
    public function testAnAlertMissedByMinutesIsStillDelivered(): void
    {
        $this->eventAt('2026-06-02 09:40:00', ['-PT1H']);

        self::assertCount(1, $this->due->due($this->now()), 'the trigger was 40 minutes ago, inside the lookback');
    }

    /**
     * One alert per occurrence, not one per series. Only the occurrence whose
     * trigger is inside the window is due — the others are the same alert on
     * different days and each gets its own turn.
     */
    public function testARecurringEventAlertsOncePerOccurrenceRatherThanOncePerSeries(): void
    {
        $this->eventAt('2026-06-02 09:10:00', ['-PT10M'], daily: true);

        $due = $this->due->due($this->now());

        self::assertCount(1, $due);
        self::assertSame(
            '2026-06-02 09:10:00',
            $due[0]->recurrenceId->format('Y-m-d H:i:s'),
            'today\'s instance, not the series',
        );

        // And tomorrow's instance is due tomorrow, from the same stored event.
        $tomorrow = $this->due->due(new DateTimeImmutable('2026-06-03 09:00:00', new DateTimeZone('UTC')));

        self::assertCount(1, $tomorrow);
        self::assertSame('2026-06-03 09:10:00', $tomorrow[0]->recurrenceId->format('Y-m-d H:i:s'));
    }

    /**
     * The override wins over the rule.
     *
     * The instance the rule put at 09:10 was dragged to 15:00, so its alert is
     * due at 14:50 and not at 09:00 — and reading it off the occurrence row is
     * what makes that true without this class knowing what an override is.
     */
    public function testAMovedOccurrenceAlertsAboutWhereItWentNotWhereTheRulePutIt(): void
    {
        $event = $this->eventAt('2026-06-02 09:10:00', ['-PT10M'], daily: true);

        $this->writer->overrideInstances($event, [
            '2026-06-02T09:10:00' => ['start' => '2026-06-02T15:00:00', 'duration' => 'PT1H'],
        ]);
        $this->em->flush();

        self::assertCount(
            0,
            $this->due->due($this->now()),
            'nothing is due at 09:00 any more — that instance was moved',
        );

        $moved = $this->due->due(new DateTimeImmutable('2026-06-02 14:50:00', new DateTimeZone('UTC')));

        self::assertCount(1, $moved);
        self::assertSame('2026-06-02 14:50:00', $moved[0]->triggerAt->format('Y-m-d H:i:s'));
        self::assertSame(
            '2026-06-02 09:10:00',
            $moved[0]->recurrenceId->format('Y-m-d H:i:s'),
            'still named by where the rule put it, which is what a delivery record is keyed on',
        );
    }

    /** An instance taken off the series has no row, so it has no alert. */
    public function testAnExcludedOccurrenceIsNotAlertedAbout(): void
    {
        $event = $this->eventAt('2026-06-02 09:10:00', ['-PT10M'], daily: true);

        $this->writer->overrideInstances($event, ['2026-06-02T09:10:00' => ['excluded' => true]]);
        $this->em->flush();

        self::assertCount(0, $this->due->due($this->now()));
    }

    /** A meeting somebody called off must not send a reminder about itself. */
    public function testACancelledOccurrenceIsNotAlertedAbout(): void
    {
        $event = $this->eventAt('2026-06-02 09:10:00', ['-PT10M'], daily: true);

        $this->writer->overrideInstances($event, ['2026-06-02T09:10:00' => ['status' => 'cancelled']]);
        $this->em->flush();

        self::assertCount(0, $this->due->due($this->now()));
    }

    /** Nor a whole series that was called off. */
    public function testACancelledEventIsNotAlertedAbout(): void
    {
        $event         = $this->eventAt('2026-06-02 09:10:00', ['-PT10M']);
        $event->status = EventStatus::Cancelled;

        $this->em->flush();

        self::assertCount(0, $this->due->due($this->now()));
    }

    /** Several alerts on one event are several deliveries, not one. */
    public function testEveryAlertOnAnEventGetsItsOwnTurn(): void
    {
        // 09:00 is "10 minutes before"; the day-before alert fired yesterday and
        // is outside the window, which is the point — one is due, not both.
        $this->eventAt('2026-06-02 09:10:00', ['-PT10M', '-P1D']);

        self::assertCount(1, $this->due->due($this->now()));

        $yesterday = $this->due->due(new DateTimeImmutable('2026-06-01 09:10:00', new DateTimeZone('UTC')));

        self::assertCount(1, $yesterday);
        self::assertSame('display/-P1D', $yesterday[0]->alert->key);
    }

    /** An event with no alerts is never a candidate, whatever else is true of it. */
    public function testAnEventWithNoAlertsIsNeverDue(): void
    {
        $this->eventAt('2026-06-02 09:10:00', []);

        self::assertCount(0, $this->due->due($this->now()));
    }

    /**
     * One meeting, one reminder.
     *
     * A meeting that reached plMail twice — extracted from its invitation onto
     * the account's calendar, mirrored from the provider onto a Remote one — is
     * two events under one UID, each carrying its own alarms. The calendar draws
     * one chip and the sweep sent two notifications, at the same second, saying
     * the same thing. The delivery ledger cannot stop it: its unique constraint
     * is keyed on the EVENT, which is precisely the thing there are two of.
     */
    public function testTwoCopiesOfOneMeetingProduceOneReminder(): void
    {
        $this->meetingOnTwoCalendars('2026-06-02 09:10:00', ['-PT10M']);

        self::assertCount(1, $this->due->due($this->now()));
    }

    /**
     * The fold is on the trigger instant, not on the meeting. A reminder the
     * user set on one of their calendars is not noise because a mirror of the
     * same meeting also carries one at a different offset — two different
     * instants are two different things the user asked for.
     */
    public function testCopiesCarryingDifferentOffsetsBothStillAlert(): void
    {
        $this->meetingOnTwoCalendars('2026-06-02 09:10:00', ['-PT10M'], ['-PT11M']);

        $due = $this->due->due($this->now());

        self::assertCount(2, $due);
        self::assertSame(
            ['2026-06-02 08:59:00', '2026-06-02 09:00:00'],
            array_map(static fn ($alert): string => $alert->triggerAt->format('Y-m-d H:i:s'), $due),
        );
    }

    /**
     * The fold is per user, and that is not a detail. This sweep is global and a
     * UID is the ORGANISER's: two people on one install invited to the same
     * meeting hold rows carrying the same UID at the same instant, and folding
     * those together would silently stop reminding one of them.
     */
    public function testTwoPeopleInvitedToOneMeetingAreBothReminded(): void
    {
        $this->eventAt('2026-06-02 09:10:00', ['-PT10M'], uid: 'shared@organiser.test');
        $this->secondUsersCopy('2026-06-02 09:10:00', '-PT10M', 'shared@organiser.test');

        self::assertCount(2, $this->due->due($this->now()));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * One meeting on two of this user's calendars under one UID, each copy
     * carrying its own alarms.
     *
     * @param list<string> $offsets       the extracted copy's alarms
     * @param list<string>|null $mirrored  the mirror's, where they differ
     */
    private function meetingOnTwoCalendars(string $startsAt, array $offsets, ?array $mirrored = null): void
    {
        $uid = 'meeting@organiser.test';

        $mirror           = new Calendar();
        $mirror->usr      = $this->user;
        $mirror->name     = 'Mirror';
        $mirror->role     = CalendarRole::Custom;
        $mirror->timeZone = 'UTC';
        $this->em->persist($mirror);
        $this->em->flush();

        $this->eventAt($startsAt, $offsets, uid: $uid);
        $this->eventAt($startsAt, $mirrored ?? $offsets, calendar: $mirror, uid: $uid);
    }

    /** The same meeting, in somebody else's mailbox — a different user entirely. */
    private function secondUsersCopy(string $startsAt, string $offset, string $uid): void
    {
        $other            = new User();
        $other->email     = 'alerts-other-' . uniqid('', true) . '@example.test';
        $other->nameFirst = 'Other';
        $other->nameLast  = 'Attendee';
        $other->roles     = ['ROLE_USER'];
        $other->password  = 'x';
        $this->em->persist($other);

        $calendar           = new Calendar();
        $calendar->usr      = $other;
        $calendar->name     = 'Theirs';
        $calendar->role     = CalendarRole::Custom;
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);
        $this->em->flush();

        $alert = $this->alerts->offsetAlert($offset, AlertAction::Display);

        self::assertNotNull($alert);

        $event      = new CalendarEvent();
        $event->uid = $uid;

        $start = new DateTimeImmutable($startsAt, new DateTimeZone('UTC'));

        $this->writer->write(
            event:    $event,
            calendar: $calendar,
            user:     $other,
            title:    'Standup',
            startsAt: $start,
            endsAt:   $start->modify('+30 minutes'),
            timeZone: 'UTC',
            alerts:   [$alert],
        );

        $this->em->flush();
    }


    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
    }

    /**
     * One event starting at $startsAt with the given offsets as display alerts.
     *
     * @param list<string> $offsets
     */
    private function eventAt(
        string    $startsAt,
        array     $offsets,
        bool      $daily = false,
        ?Calendar $calendar = null,
        string    $uid = '',
    ): CalendarEvent {
        $utc   = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($startsAt, $utc);

        $alerts = [];

        foreach ($offsets as $offset) {
            $alert = $this->alerts->offsetAlert($offset, AlertAction::Display);

            self::assertNotNull($alert, 'the fixture must be an offset the reader accepts');

            $alerts[] = $alert;
        }

        $fresh = new CalendarEvent();

        // Before write(), which mints one for an event that has none: a copy
        // carries the MEETING's UID rather than getting one of its own, and
        // that shared identity is what makes the two rows one reminder.
        if ('' !== $uid) {
            $fresh->uid = $uid;
        }

        $event = $this->writer->write(
            event:          $fresh,
            calendar:       $calendar ?? $this->calendar,
            user:           $this->user,
            title:          'Standup',
            startsAt:       $start,
            endsAt:         $start->modify('+30 minutes'),
            timeZone:       'UTC',
            recurrenceRule: true === $daily ? ['@type' => 'RecurrenceRule', 'frequency' => 'daily'] : null,
            alerts:         $alerts,
        );

        $this->em->flush();

        return $event;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'alerts-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Alert';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->name     = 'Alert fixture';
        $calendar->role     = CalendarRole::Custom;
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;
    }
}
