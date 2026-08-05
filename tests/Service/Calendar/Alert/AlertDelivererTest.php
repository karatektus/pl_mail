<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Alert;

use App\Domain\DTO\Calendar\DueAlert;
use App\Domain\Enum\Calendar\AlertAction;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarAlertDelivery;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarAlertDeliveryRepository;
use App\Service\Calendar\Alert\AlertDeliverer;
use App\Service\Calendar\Alert\AlertReader;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An alert goes off exactly once, and the guarantee is the database's rather
 * than the code's.
 *
 * The claim under test is not "the deliverer remembers what it sent" — it is
 * that **nothing can send an alert twice**, including two sweeps running at the
 * same time, a scheduler replaying a run a worker missed, and a process killed
 * between the push and the record of it. The order is what buys that: the row is
 * written first, in one `INSERT … ON CONFLICT DO NOTHING`, and the answer to
 * "did that insert happen?" is the same answer as "am I the one who sends this?".
 *
 * The two shapes that were rejected are worth naming because both look correct:
 *
 *   Send, then record. The gap between the two is where the process dies, and a
 *   sweep that runs every minute has sixty chances an hour to be interrupted.
 *
 *   Read, decide, insert. Two sweeps both read nothing and both send; the
 *   constraint then rejects one of them, after it has already notified. A check
 *   is not a lock.
 *
 * The last test here is the one that matters most, because it does not go
 * through the deliverer at all: it asserts that the DATABASE refuses the second
 * row. Everything above it would keep passing if the unique index were dropped.
 */
final class AlertDelivererTest extends KernelTestCase
{
    private const string NOW = '2026-06-02 09:00:00';

    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarAlertDeliveryRepository $deliveries;
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
        $this->deliveries = $container->get(CalendarAlertDeliveryRepository::class);
        $this->writer     = $container->get(CalendarEventWriter::class);
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
     * The whole feature in one assertion: two sweeps see the same due alert and
     * the user is notified once.
     */
    public function testASecondSweepDoesNotNotifyAgain(): void
    {
        $channel   = new RecordingAlertChannel();
        $deliverer = $this->deliverer($channel);
        $due       = $this->dueAlert();

        self::assertTrue($deliverer->deliver($due), 'the first sweep owns it');
        self::assertFalse($deliverer->deliver($due), 'the second sweep is told somebody else has it');

        self::assertCount(1, $channel->delivered, 'the user must be notified once');
    }

    /**
     * A delivery that could not go anywhere is still claimed.
     *
     * Un-claiming would look kinder and is the trap: the alert stays inside the
     * lookback window for an hour, so the sweep would retry it sixty times and
     * write sixty warnings. "Your meeting starts in ten minutes" is false by the
     * second attempt anyway.
     */
    public function testAnAlertThatCouldNotBeDeliveredIsNotRetried(): void
    {
        $channel   = new RecordingAlertChannel(succeeds: false);
        $deliverer = $this->deliverer($channel);
        $due       = $this->dueAlert();

        $deliverer->deliver($due);
        $deliverer->deliver($due);

        self::assertCount(1, $channel->delivered, 'one attempt, not one per sweep');
    }

    /**
     * A fresh install: no VAPID keys, no subscribed device, no mail account.
     *
     * The real channels are wired here rather than the recording one, because
     * the thing being pinned is that the ordinary state of a new install
     * degrades to a logged warning instead of an exception that fails the whole
     * sweep and strands every other user's alerts behind it.
     */
    public function testAUserWithNoDeviceAndNoMailAccountDegradesRatherThanThrowing(): void
    {
        $deliverer = self::getContainer()->get(AlertDeliverer::class);
        $due       = $this->dueAlert();

        self::assertTrue($deliverer->deliver($due));
        self::assertSame(1, $this->rowCount(), 'claimed, so the impossible delivery is not attempted again');
    }

    /** Two occurrences of one series are two alerts, and both are delivered. */
    public function testTwoOccurrencesOfTheSameAlertAreBothDelivered(): void
    {
        $channel   = new RecordingAlertChannel();
        $deliverer = $this->deliverer($channel);
        $event     = $this->event();

        $deliverer->deliver($this->dueAlert($event, '2026-06-02 09:10:00'));
        $deliverer->deliver($this->dueAlert($event, '2026-06-03 09:10:00'));

        self::assertCount(2, $channel->delivered, 'the recurrence id is part of the identity');
    }

    /**
     * Records older than the window in which they could still be consulted go.
     *
     * The cutoff is the command's, and it is far beyond DueAlertReader::LOOKBACK
     * on purpose — pruning inside that window would let an alert fire twice.
     */
    public function testPruningRemovesOnlyRecordsPastTheCutoff(): void
    {
        $event = $this->event();

        $this->deliveries->claim($this->dueAlert($event, '2026-06-02 09:10:00'));
        $this->deliveries->claim($this->dueAlert($event, '2026-05-01 09:10:00'));

        self::assertSame(2, $this->rowCount());
        self::assertSame(1, $this->deliveries->pruneBefore(new DateTimeImmutable('2026-06-01 00:00:00')));
        self::assertSame(1, $this->rowCount());
    }

    /**
     * The guarantee, asserted against the schema rather than against the code.
     *
     * Written through the ORM on purpose: it bypasses claim() entirely, so this
     * fails if the unique index is ever dropped or its columns reordered —
     * which every test above would survive.
     */
    public function testTheDatabaseRefusesASecondRecordForTheSameAlertAndOccurrence(): void
    {
        $event = $this->event();

        $this->em->persist($this->record($event));
        $this->em->flush();

        $this->em->persist($this->record($event));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->em->flush();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function deliverer(RecordingAlertChannel $channel): AlertDeliverer
    {
        return new AlertDeliverer([$channel], $this->deliveries, new NullLogger());
    }

    private function rowCount(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT count(*) FROM calendar_alert_delivery WHERE usr_id = :usr',
            ['usr' => $this->user->id],
        );
    }

    private function record(CalendarEvent $event): CalendarAlertDelivery
    {
        $delivery               = new CalendarAlertDelivery();
        $delivery->event        = $event;
        $delivery->usr          = $this->user;
        $delivery->alertKey     = 'display/-PT10M';
        $delivery->recurrenceId = new DateTimeImmutable('2026-06-02 09:10:00', new DateTimeZone('UTC'));
        $delivery->triggerAt    = new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));

        return $delivery;
    }

    private function dueAlert(?CalendarEvent $event = null, string $recurrenceId = '2026-06-02 09:10:00'): DueAlert
    {
        $utc   = new DateTimeZone('UTC');
        $event = $event ?? $this->event();
        $alert = $this->alerts->offsetAlert('-PT10M', AlertAction::Display);

        self::assertNotNull($alert);

        $startsAt = new DateTimeImmutable($recurrenceId, $utc);

        return new DueAlert(
            event:        $event,
            user:         $this->user,
            eventId:      (int) $event->id,
            userId:       (int) $this->user->id,
            alert:        $alert,
            recurrenceId: $startsAt,
            startsAt:     $startsAt,
            endsAt:       $startsAt->modify('+30 minutes'),
            // Derived from the occurrence rather than fixed at NOW, because the
            // prune goes by trigger_at: two occurrences of one series that
            // shared a trigger instant would make "prune the old ones" a test
            // that cannot tell them apart.
            triggerAt:    $startsAt->modify('-10 minutes'),
        );
    }

    private function event(): CalendarEvent
    {
        $utc   = new DateTimeZone('UTC');
        $start = new DateTimeImmutable('2026-06-02 09:10:00', $utc);
        $alert = $this->alerts->offsetAlert('-PT10M', AlertAction::Display);

        self::assertNotNull($alert);

        $event = $this->writer->write(
            event:    new CalendarEvent(),
            calendar: $this->calendar,
            user:     $this->user,
            title:    'Standup',
            startsAt: $start,
            endsAt:   $start->modify('+30 minutes'),
            timeZone: 'UTC',
            alerts:   [$alert],
        );

        $this->em->flush();

        return $event;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'deliver-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Deliver';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->name     = 'Delivery fixture';
        $calendar->role     = CalendarRole::Custom;
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;
    }
}
