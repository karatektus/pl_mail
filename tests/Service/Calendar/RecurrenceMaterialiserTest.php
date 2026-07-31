<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventOccurrenceRepository;
use App\Service\Calendar\RecurrenceMaterialiser;
use App\Service\Calendar\RecurrenceRuleConverter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Recurrence is the part of a calendar that is easy to get almost right.
 *
 * The cases here are the ones that separate "works on my Tuesday" from
 * correct: a DST transition, an ordinal weekday, a rule with no end, and an
 * override that moves or cancels one instance out of a series.
 *
 * The DST case is the one worth reading. A 09:00 Berlin standup must stay at
 * 09:00 Berlin on both sides of the March transition, which means its UTC
 * instant moves by an hour. Expanding in UTC — the obvious implementation —
 * passes every test written in UTC and moves everybody's meeting twice a year.
 */
final class RecurrenceMaterialiserTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private RecurrenceMaterialiser $materialiser;
    private Calendar $calendar;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        // Built by hand rather than pulled from the container: the converter
        // has no dependencies worth wiring, and both services are inlined at
        // compile time whenever the only caller is another service.
        $this->materialiser = new RecurrenceMaterialiser(
            $container->get(CalendarEventOccurrenceRepository::class),
            new RecurrenceRuleConverter(),
            $this->em,
            $container->get(LoggerInterface::class),
        );

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

    /** A one-off still gets a row, so reads have exactly one code path. */
    public function testANonRecurringEventGetsASingleOccurrence(): void
    {
        $event = $this->event('2026-03-10 09:00', '2026-03-10 10:00');

        $this->materialise($event);

        self::assertCount(1, $event->occurrences);
        self::assertFalse($event->isRecurring);
        self::assertEquals($event->endsAt, $event->recurrenceUntil);
    }

    /**
     * The one that matters. Berlin goes to summer time on 2026-03-29, so a
     * weekly 09:00 local meeting is 08:00 UTC before it and 07:00 UTC after.
     */
    public function testWeeklyExpansionHoldsLocalTimeAcrossADstTransition(): void
    {
        $event = $this->event(
            '2026-03-23 09:00',
            '2026-03-23 10:00',
            timeZone: 'Europe/Berlin',
            rule: ['frequency' => 'weekly', 'count' => 4],
        );

        $this->materialise($event, now: '2026-03-01 00:00');

        $starts = $this->startsInZone($event, 'Europe/Berlin');

        self::assertSame(
            ['2026-03-23 09:00', '2026-03-30 09:00', '2026-04-06 09:00', '2026-04-13 09:00'],
            $starts,
            'local wall-clock time must not drift',
        );

        $utc = $this->startsInZone($event, 'UTC');

        self::assertSame('2026-03-23 08:00', $utc[0], 'winter time is UTC+1');
        self::assertSame('2026-03-30 07:00', $utc[1], 'summer time is UTC+2');
    }

    /** "The last Friday of the month" — the ordinal BYDAY conversion. */
    public function testMonthlyOnTheLastFriday(): void
    {
        $event = $this->event(
            '2026-01-30 15:00',
            '2026-01-30 16:00',
            rule: [
                'frequency' => 'monthly',
                'count'     => 3,
                'byDay'     => [['day' => 'fr', 'nthOfPeriod' => -1]],
            ],
        );

        $this->materialise($event, now: '2026-01-01 00:00');

        self::assertSame(
            ['2026-01-30 15:00', '2026-02-27 15:00', '2026-03-27 15:00'],
            $this->startsInZone($event, 'UTC'),
        );
    }

    public function testUntilStopsTheSeries(): void
    {
        $event = $this->event(
            '2026-05-04 08:00',
            '2026-05-04 08:30',
            rule: ['frequency' => 'daily', 'until' => '2026-05-07T00:00:00'],
        );

        $this->materialise($event, now: '2026-05-01 00:00');

        self::assertSame(
            ['2026-05-04 08:00', '2026-05-05 08:00', '2026-05-06 08:00'],
            $this->startsInZone($event, 'UTC'),
        );
        self::assertNotNull($event->recurrenceUntil, 'a bounded rule has a known end');
    }

    public function testIntervalIsHonoured(): void
    {
        $event = $this->event(
            '2026-06-01 12:00',
            '2026-06-01 13:00',
            rule: ['frequency' => 'weekly', 'interval' => 2, 'count' => 3],
        );

        $this->materialise($event, now: '2026-06-01 00:00');

        self::assertSame(
            ['2026-06-01 12:00', '2026-06-15 12:00', '2026-06-29 12:00'],
            $this->startsInZone($event, 'UTC'),
        );
    }

    /**
     * No UNTIL, no COUNT. Stops at the horizon, and says so by leaving
     * recurrenceUntil null — which is how the nightly sweep finds it again.
     */
    public function testAnUnboundedRuleStopsAtTheHorizonAndStaysExtendable(): void
    {
        $event = $this->event(
            '2026-01-01 06:00',
            '2026-01-01 06:30',
            rule: ['frequency' => 'daily'],
        );

        $this->materialise($event, now: '2026-01-01 00:00');

        $count = count($event->occurrences);

        self::assertGreaterThan(700, $count, 'roughly two years of daily');
        self::assertLessThanOrEqual(RecurrenceMaterialiser::MAX_OCCURRENCES, $count);
        self::assertNull($event->recurrenceUntil, 'unfinished, so the sweep must revisit it');
    }

    /**
     * Hourly forever is ~17,500 instances inside the horizon. The cap is what
     * keeps one event from being most of the table.
     */
    public function testAnHourlyRuleIsCappedRatherThanFillingTheHorizon(): void
    {
        $event = $this->event(
            '2026-01-01 00:00',
            '2026-01-01 00:30',
            rule: ['frequency' => 'hourly'],
        );

        $this->materialise($event, now: '2026-01-01 00:00');

        self::assertCount(RecurrenceMaterialiser::MAX_OCCURRENCES, $event->occurrences);
        self::assertNull($event->recurrenceUntil, 'capped, so the sweep must revisit it');
    }

    /**
     * FREQ=SECONDLY is legal in both RFCs and sabre accepts it at validation,
     * but its iterator has no branch to advance on — it yields the same instant
     * forever. The cap alone would turn that into a thousand identical rows,
     * which looks like it worked. So the converter refuses the frequency and
     * the event degrades to a single visible occurrence instead.
     */
    public function testASecondlyRuleDegradesRatherThanRepeatingOneInstant(): void
    {
        $event = $this->event(
            '2026-01-01 00:00',
            '2026-01-01 00:01',
            rule: ['frequency' => 'secondly'],
        );

        $this->materialise($event, now: '2026-01-01 00:00');

        self::assertCount(1, $event->occurrences);
        self::assertFalse($event->isRecurring);
    }

    /** recurrenceOverrides with "excluded" is JSCalendar's EXDATE. */
    public function testAnExcludedInstanceIsSkipped(): void
    {
        $event = $this->event(
            '2026-02-02 10:00',
            '2026-02-02 11:00',
            rule: ['frequency' => 'daily', 'count' => 4],
            overrides: ['2026-02-03T10:00:00' => ['excluded' => true]],
        );

        $this->materialise($event, now: '2026-02-01 00:00');

        self::assertSame(
            ['2026-02-02 10:00', '2026-02-04 10:00', '2026-02-05 10:00'],
            $this->startsInZone($event, 'UTC'),
        );
    }

    /**
     * A moved instance keeps its ORIGINAL start as recurrenceId. That is the
     * only stable way to say "the one that was meant to be on the 3rd" once it
     * has been dragged to the 5th, and it is what a later update matches on.
     */
    public function testAnOverrideMovesOneInstanceButKeepsItsIdentity(): void
    {
        $event = $this->event(
            '2026-02-02 10:00',
            '2026-02-02 11:00',
            rule: ['frequency' => 'daily', 'count' => 3],
            overrides: ['2026-02-03T10:00:00' => ['start' => '2026-02-03T16:30:00']],
        );

        $this->materialise($event, now: '2026-02-01 00:00');

        self::assertSame(
            ['2026-02-02 10:00', '2026-02-03 16:30', '2026-02-04 10:00'],
            $this->startsInZone($event, 'UTC'),
        );

        $moved = $this->occurrenceAt($event, '2026-02-03 16:30');

        self::assertTrue($moved->isOverride);
        self::assertSame(
            '2026-02-03 10:00',
            $moved->recurrenceId->format('Y-m-d H:i'),
            'identity is where it was meant to be, not where it went',
        );
        self::assertSame(3600, $moved->endsAt->getTimestamp() - $moved->startsAt->getTimestamp());
    }

    /** Cancelling one instance keeps the row, struck through rather than gone. */
    public function testACancelledInstanceIsKeptAndFlagged(): void
    {
        $event = $this->event(
            '2026-02-02 10:00',
            '2026-02-02 11:00',
            rule: ['frequency' => 'daily', 'count' => 3],
            overrides: ['2026-02-03T10:00:00' => ['status' => 'cancelled']],
        );

        $this->materialise($event, now: '2026-02-01 00:00');

        self::assertCount(3, $event->occurrences);
        self::assertTrue($this->occurrenceAt($event, '2026-02-03 10:00')->cancelled);
    }

    /** Re-materialising replaces, never appends. */
    public function testMaterialisingTwiceDoesNotDuplicate(): void
    {
        $event = $this->event(
            '2026-07-01 09:00',
            '2026-07-01 10:00',
            rule: ['frequency' => 'daily', 'count' => 5],
        );

        $this->materialise($event, now: '2026-07-01 00:00');
        $this->materialise($event, now: '2026-07-01 00:00');

        self::assertCount(5, $event->occurrences);
        self::assertSame(5, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM calendar_event_occurrence WHERE event_id = ?',
            [$event->id],
        ));
    }

    /**
     * A rule nothing can expand — a frequency that is not one — degrades to a
     * single occurrence. An empty calendar would be the silent failure.
     */
    public function testAnUnusableRuleFallsBackToOneOccurrence(): void
    {
        $event = $this->event(
            '2026-08-01 09:00',
            '2026-08-01 10:00',
            rule: ['frequency' => 'fortnightly'],
        );

        $this->materialise($event, now: '2026-08-01 00:00');

        self::assertCount(1, $event->occurrences);
        self::assertFalse($event->isRecurring);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function materialise(CalendarEvent $event, ?string $now = null): void
    {
        $this->materialiser->materialise(
            $event,
            null === $now ? null : new DateTimeImmutable($now, new DateTimeZone('UTC')),
        );

        $this->em->flush();
    }

    /**
     * @return list<string> occurrence starts, rendered in one zone, in order
     */
    private function startsInZone(CalendarEvent $event, string $zone): array
    {
        $starts = [];

        foreach ($event->occurrences as $occurrence) {
            $starts[] = $occurrence->startsAt
                ->setTimezone(new DateTimeZone($zone))
                ->format('Y-m-d H:i');
        }

        sort($starts);

        return $starts;
    }

    private function occurrenceAt(CalendarEvent $event, string $utcStart): object
    {
        foreach ($event->occurrences as $occurrence) {
            if ($occurrence->startsAt->format('Y-m-d H:i') === $utcStart) {
                return $occurrence;
            }
        }

        self::fail(sprintf('No occurrence starting at %s', $utcStart));
    }

    /**
     * @param array<string,mixed>|null              $rule
     * @param array<string,array<string,mixed>>|null $overrides
     */
    private function event(
        string  $startsAt,
        string  $endsAt,
        ?string $timeZone = null,
        ?array  $rule = null,
        ?array  $overrides = null,
    ): CalendarEvent {
        $zone = new DateTimeZone($timeZone ?? 'UTC');

        $jscalendar = ['@type' => 'Event', 'title' => 'Fixture'];

        if (null !== $rule) {
            $jscalendar['recurrenceRules'] = [$rule];
        }

        if (null !== $overrides) {
            $jscalendar['recurrenceOverrides'] = $overrides;
        }

        $event             = new CalendarEvent();
        $event->calendar   = $this->calendar;
        $event->usr        = $this->user;
        $event->uid        = uniqid('fixture-', true) . '@plmail.test';
        $event->title      = 'Fixture';
        $event->timeZone   = $timeZone;
        $event->jscalendar = $jscalendar;
        $event->startsAt   = (new DateTimeImmutable($startsAt, $zone))->setTimezone(new DateTimeZone('UTC'));
        $event->endsAt     = (new DateTimeImmutable($endsAt, $zone))->setTimezone(new DateTimeZone('UTC'));

        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    private function seedCalendar(): Calendar
    {
        $user = new User();
        $user
            ->setEmail('recurrence-' . uniqid('', true) . '@example.test')
            ->setNameFirst('Recurrence')
            ->setNameLast('Fixture')
            ->setRoles(['ROLE_USER'])
            ->setPassword('x');
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
