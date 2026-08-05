<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\DTO\Calendar\CalendarChangeSet;
use App\Domain\DTO\Calendar\RemoteEvent;
use App\Domain\DTO\Calendar\RemoteInstance;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Service\Calendar\Alert\AlertReader;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\CalendarPuller;
use App\Service\Calendar\RecurrenceRuleConverter;
use App\Service\Calendar\Sync\CalDav\CalDavEventConverter;
use App\Service\Calendar\Sync\Google\GoogleEventMapper;
use App\Service\Calendar\Sync\Google\GoogleRecurrenceMapper;
use App\Service\Calendar\Sync\Graph\GraphEventMapper;
use App\Service\Calendar\Sync\Graph\GraphRecurrenceMapper;
use App\Service\Calendar\Sync\Graph\GraphTimeZoneMapper;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A recurring event, from what a provider says about it to the rows a calendar
 * view reads.
 *
 * Two bugs, and both of them are only visible at this distance — each half
 * works on its own.
 *
 * **A recurring event arrived and was drawn once.** The rule reached plMail as
 * an RRULE from a CalDAV server or an emailed invitation, was stored verbatim
 * because RRULE→JSCalendar had not been written, and RecurrenceMaterialiser
 * reads recurrenceRules and nothing else. A weekly standup therefore appeared on
 * the calendar exactly once, on the day the series began, and looked for all the
 * world like an event somebody had booked one morning.
 *
 * **An instance somebody moved was drawn where it used to be, or twice.** All
 * three providers report a changed occurrence separately from its series, and
 * all three did something different and wrong with it: Google's became its own
 * local row, so the day it moved to showed a duplicate; Graph's was collapsed
 * onto the series, so it stayed at its original time; a CalDAV RECURRENCE-ID
 * component was read as nothing at all. The answer is the same in all three
 * cases and it is JSCalendar's — a recurrenceOverride on the master, keyed by
 * the start the rule originally gave the instance.
 *
 * Against a real container and a real database, and through the real provider
 * mappings rather than hand-written RemoteEvents wherever the claim is about
 * what a provider says. Every collaborator here is final, so nothing can be
 * doubled anyway, and what is worth pinning is the state a calendar view will
 * find: which occurrence rows exist, on which days, and how many rows there are
 * in total.
 */
final class CalendarPullerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventRepository $events;
    private CalendarPuller $puller;
    private RecordingLogger $logger;
    private Calendar $calendar;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->events     = $container->get(CalendarEventRepository::class);
        $this->logger     = new RecordingLogger();

        // Assembled by hand for one reason: where the log lines go. Everything
        // else is the real service, including the writer and the materialiser
        // behind it.
        $this->puller = new CalendarPuller(
            $this->events,
            $container->get(CalendarEventWriter::class),
            new RecurrenceRuleConverter(),
            $this->em,
            $this->logger,
        );

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

    // ── The rule ──────────────────────────────────────────────────────────

    public function testARecurringCalDavEventIsDrawnEveryWeekRatherThanOnce(): void
    {
        $remote = $this->calDav($this->weeklyIcs());

        self::assertNotNull($remote);
        self::assertSame(1, $this->apply([$remote]));

        $event = $this->events->findOneByRemoteId($this->calendar, 'https://dav.example.com/c/weekly.ics');

        self::assertNotNull($event);
        self::assertTrue($event->isRecurring, 'a weekly meeting is a recurring event');
        self::assertCount(
            10,
            $event->occurrences,
            'the rule says ten Tuesdays; kept verbatim it said nothing and one row was written',
        );
        self::assertSame(
            ['2026-08-04 10:00', '2026-08-11 10:00', '2026-08-18 10:00'],
            array_slice($this->startsOf($event), 0, 3),
            'and every one of them at 10:00 Berlin',
        );
    }

    public function testAnUnconvertibleRuleLeavesTheEventVisibleRatherThanEmpty(): void
    {
        // FREQ=SECONDLY is refused rather than converted — sabre's iterator
        // accepts it and then yields the same instant forever. One occurrence is
        // visibly wrong; a thousand identical rows looks like it worked.
        $remote = $this->calDav(str_replace('FREQ=WEEKLY;COUNT=10', 'FREQ=SECONDLY;COUNT=10', $this->weeklyIcs()));

        self::assertNotNull($remote);
        $this->apply([$remote]);

        $event = $this->events->findOneByRemoteId($this->calendar, 'https://dav.example.com/c/weekly.ics');

        self::assertNotNull($event);
        self::assertFalse($event->isRecurring);
        self::assertCount(1, $event->occurrences);
        self::assertSame(
            'FREQ=SECONDLY;COUNT=10',
            $event->jscalendar['plmail:rrule'] ?? null,
            'kept verbatim, so a push does not un-repeat it at the server',
        );
    }

    // ── The moved instance ────────────────────────────────────────────────

    public function testAMovedCalDavInstanceIsDrawnWhereItWentAndNotWhereItWas(): void
    {
        $remote = $this->calDav($this->weeklyIcs(<<<'ICS'
            BEGIN:VEVENT
            UID:weekly-1
            RECURRENCE-ID;TZID=Europe/Berlin:20260811T100000
            DTSTART;TZID=Europe/Berlin:20260811T160000
            DTEND;TZID=Europe/Berlin:20260811T170000
            SUMMARY:Standup (moved)
            END:VEVENT
            ICS));

        self::assertNotNull($remote);
        $this->apply([$remote]);

        $event = $this->events->findOneByRemoteId($this->calendar, 'https://dav.example.com/c/weekly.ics');

        self::assertNotNull($event);
        self::assertCount(10, $event->occurrences, 'moving one instance does not add or remove one');
        self::assertContains('2026-08-11 16:00', $this->startsOf($event));
        self::assertNotContains('2026-08-11 10:00', $this->startsOf($event), 'not still where it was');

        $moved = $this->occurrenceAt($event, '2026-08-11 16:00');

        self::assertTrue($moved->isOverride);
        self::assertSame(3600, $moved->endsAt?->getTimestamp() - $moved->startsAt?->getTimestamp(), 'and an hour long now');
        self::assertSame(1, $this->rowCount(), 'one series is one row');
    }

    public function testAMovedGoogleInstanceLandsOnItsSeriesRatherThanBecomingASecondEvent(): void
    {
        $this->apply([$this->google($this->googleSeries())]);

        // Its own resource, with its own id and its own iCalUID suffix, which is
        // exactly why it used to be written as an event of its own.
        $this->apply([$this->google($this->googleMovedInstance())]);

        $event = $this->events->findOneByRemoteId($this->calendar, 'ev-1');

        self::assertNotNull($event);
        self::assertSame(1, $this->rowCount(), 'a moved occurrence is not a second meeting');
        self::assertContains('2026-08-11 16:00', $this->startsOf($event));
        self::assertNotContains('2026-08-11 10:00', $this->startsOf($event));
        self::assertCount(10, $event->occurrences);
    }

    public function testAMovedGraphInstanceLandsAtItsNewTimeRatherThanItsOriginalOne(): void
    {
        $this->apply([$this->graph($this->graphSeries())]);
        $this->apply([$this->graph($this->graphException(), 'MASTER', '2026-08-11T08:00:00Z')]);

        $event = $this->events->findOneByRemoteId($this->calendar, 'MASTER');

        self::assertNotNull($event);
        self::assertSame(1, $this->rowCount());
        self::assertContains('2026-08-11 16:00', $this->startsOf($event));
        self::assertNotContains('2026-08-11 10:00', $this->startsOf($event));
    }

    public function testACancelledInstanceStopsBeingDrawnWithoutTakingTheSeriesWithIt(): void
    {
        $this->apply([$this->google($this->googleSeries())]);

        $this->apply([$this->google([
            'id'                => 'ev-1_20260811T080000Z',
            'status'            => 'cancelled',
            'recurringEventId'  => 'ev-1',
            'originalStartTime' => ['dateTime' => '2026-08-11T10:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
        ])]);

        $event = $this->events->findOneByRemoteId($this->calendar, 'ev-1');

        self::assertNotNull($event, 'the series is alive; one instance of it is not');
        self::assertSame(1, $this->rowCount());
        self::assertCount(9, $event->occurrences, 'nine of the ten Tuesdays are left');
        self::assertNotContains('2026-08-11 10:00', $this->startsOf($event));
    }

    // ── Ordering, and what a window is a statement about ──────────────────

    public function testAnOverrideThatArrivesBeforeItsMasterStillLandsOnIt(): void
    {
        // One window, in the order the provider chose to report it. Applied in
        // arrival order the override would find no row, be dropped, and the
        // instance would be drawn at its original time until something else
        // happened to that series.
        $this->apply([
            $this->google($this->googleMovedInstance()),
            $this->google($this->googleSeries()),
        ]);

        $event = $this->events->findOneByRemoteId($this->calendar, 'ev-1');

        self::assertNotNull($event);
        self::assertSame(1, $this->rowCount());
        self::assertContains('2026-08-11 16:00', $this->startsOf($event));
    }

    public function testAnOverrideForASeriesNobodyHasIsDroppedRatherThanInventingOne(): void
    {
        // A master built from one instance would be a series with one occurrence
        // and no rule, which is a meeting nobody recognises rather than a
        // meeting that is missing.
        self::assertSame(0, $this->apply([$this->google($this->googleMovedInstance())]));

        self::assertSame(0, $this->rowCount());
        self::assertCount(
            1,
            $this->logger->matching('info', 'an instance arrived for a series that is not here'),
            'and it says so, because "one instance is at the wrong time" is otherwise unexplainable',
        );
    }

    public function testADeltaWindowAddsToTheOverridesAlreadyThereRatherThanReplacingThem(): void
    {
        $this->apply([$this->google($this->googleSeries())]);
        $this->apply([$this->google($this->googleMovedInstance())]);

        // A second window naming a different instance says nothing about the
        // first one. Replacing here would un-move the meeting that was moved
        // last week every time somebody moves another.
        $this->apply([$this->google($this->googleMovedInstance(
            id: 'ev-1_20260818T080000Z',
            original: '2026-08-18T10:00:00+02:00',
            start: '2026-08-18T14:00:00+02:00',
            end: '2026-08-18T15:00:00+02:00',
        ))]);

        $event = $this->events->findOneByRemoteId($this->calendar, 'ev-1');

        self::assertNotNull($event);
        self::assertContains('2026-08-11 16:00', $this->startsOf($event), 'the first move survives the second');
        self::assertContains('2026-08-18 14:00', $this->startsOf($event));
    }

    public function testAFullReadIsTheWholeTruthAboutASeriesAndPutsAMovedInstanceBack(): void
    {
        $this->apply([$this->google($this->googleSeries())]);
        $this->apply([$this->google($this->googleMovedInstance())]);

        // The instance was dragged back to where the rule puts it, so Google no
        // longer reports it as an instance at all — the only way to learn that
        // is a listing that does not mention it. Merged, the override would
        // survive forever and the meeting would stay in the afternoon.
        $this->apply([$this->google($this->googleSeries('"etag-2"')), $this->google($this->googleMovedInstance(
            original: '2026-08-25T10:00:00+02:00',
            start: '2026-08-25T14:00:00+02:00',
            end: '2026-08-25T15:00:00+02:00',
        ))], wasFullRead: true);

        $event = $this->events->findOneByRemoteId($this->calendar, 'ev-1');

        self::assertNotNull($event);
        self::assertContains('2026-08-11 10:00', $this->startsOf($event), 'back on the pattern');
        self::assertNotContains('2026-08-11 16:00', $this->startsOf($event));
        self::assertContains('2026-08-25 14:00', $this->startsOf($event), 'and the one the read did mention is honoured');
    }

    public function testAFullReadPrunesTheDuplicateRowsMovedInstancesUsedToCreate(): void
    {
        // The rows an older plMail wrote for every moved occurrence. An
        // instance's own remote id is deliberately not counted as "seen", so a
        // full read is what finally clears them out.
        $this->apply([$this->google($this->googleSeries())]);
        $this->duplicateRow('ev-1_20260811T080000Z');

        self::assertSame(2, $this->rowCount());

        $this->apply(
            [$this->google($this->googleSeries('"etag-2"')), $this->google($this->googleMovedInstance())],
            wasFullRead: true,
        );

        self::assertSame(1, $this->rowCount(), 'the duplicate goes, the series stays');
    }

    // ── The cancelled instance nobody could name ──────────────────────────

    public function testWhatAnInstanceIdMeansIsWrittenDownWhileTheOccurrenceStillExists(): void
    {
        // The only chance there is to learn it. Graph reports the deletion of an
        // occurrence as that occurrence's id and nothing else, and by then the
        // resource is gone — so either an earlier window recorded which
        // occurrence the id was, or the removal is unreadable forever.
        $this->apply([$this->graph($this->graphSeries())], instances: [
            $this->instance('OCC-AUG11', '2026-08-11T08:00:00Z'),
        ]);

        $event = $this->events->findOneByRemoteId($this->calendar, 'MASTER');

        self::assertNotNull($event);
        self::assertSame(
            ['OCC-AUG11' => '2026-08-11T08:00:00Z'],
            $event->remoteInstances,
            'UTC with the Z on it: read back in the reader\'s own zone this would be a different occurrence',
        );
    }

    public function testARemovedInstanceIdBecomesAnExclusionOnItsSeriesRatherThanNothingAtAll(): void
    {
        $this->apply([$this->graph($this->graphSeries())], instances: [
            $this->instance('OCC-AUG11', '2026-08-11T08:00:00Z'),
        ]);

        // What Graph actually sends when somebody deletes one occurrence in
        // Outlook: an id, and no statement about what it belongs to. Applied as
        // it stands it matches no row — an instance has never been one — so the
        // deletion did nothing and the standup of the 11th stayed on screen.
        $this->apply([RemoteEvent::deleted('OCC-AUG11')]);

        $event = $this->events->findOneByRemoteId($this->calendar, 'MASTER');

        self::assertNotNull($event, 'the series is alive; one instance of it is not');
        self::assertSame(1, $this->rowCount());
        self::assertCount(9, $event->occurrences, 'nine of the ten Tuesdays are left');
        self::assertNotContains('2026-08-11 10:00', $this->startsOf($event));
    }

    public function testATombstoneForAnEventIsStillAnEventDeletion(): void
    {
        // The other side of the same lookup. An id that names no instance has to
        // go on meaning what it always meant, or recognising instances would
        // have cost the ability to delete an event.
        $this->apply([$this->google($this->googleSeries())]);

        self::assertSame(1, $this->rowCount());

        $this->apply([RemoteEvent::deleted('ev-1', 'uid-1@google.com')]);

        self::assertSame(0, $this->rowCount());
    }

    public function testAnInstanceIdIsForgottenOnceNoViewCouldEverDrawIt(): void
    {
        // The map is written on every window that mentions a series, so it grows
        // without a bound of its own. The horizon is the bound that means
        // something: an instance older than the occurrences are materialised to
        // cannot be shown and cannot be cancelled from anywhere, so its id
        // answers no question.
        $stale = new \DateTimeImmutable('-2 years', new DateTimeZone('UTC'));

        $this->apply([$this->graph($this->graphSeries())], instances: [
            $this->instance('OCC-OLD', $stale->format('Y-m-d\TH:i:s\Z')),
            $this->instance('OCC-AUG11', '2026-08-11T08:00:00Z'),
        ]);

        $event = $this->events->findOneByRemoteId($this->calendar, 'MASTER');

        self::assertNotNull($event);
        self::assertSame(['OCC-AUG11' => '2026-08-11T08:00:00Z'], $event->remoteInstances);
    }

    public function testASecondIdForTheSameOccurrenceReplacesTheFirstRatherThanJoiningIt(): void
    {
        // Graph re-keys an occurrence for some edits. Both ids left in the map,
        // a push would address whichever came first and patch a resource that is
        // not there.
        $this->apply([$this->graph($this->graphSeries())], instances: [
            $this->instance('OCC-OLD', '2026-08-11T08:00:00Z'),
        ]);
        $this->apply([], instances: [$this->instance('OCC-NEW', '2026-08-11T08:00:00Z')]);

        $event = $this->events->findOneByRemoteId($this->calendar, 'MASTER');

        self::assertNotNull($event);
        self::assertSame(['OCC-NEW' => '2026-08-11T08:00:00Z'], $event->remoteInstances);
    }

    public function testAnInstanceIdForASeriesNobodyMirrorsIsDroppedWithoutComplaint(): void
    {
        // A window may name instances of a series on a calendar plMail does not
        // hold. There is nothing to record it against, and it is not a fault
        // worth a line — unlike an override, which is a change that will not be
        // applied.
        self::assertSame(0, $this->apply([], instances: [$this->instance('OCC-AUG11', '2026-08-11T08:00:00Z')]));

        self::assertSame(
            [],
            $this->logger->matching('info', 'an instance arrived for a series that is not here'),
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @param list<RemoteEvent>    $events
     * @param list<RemoteInstance> $instances
     */
    private function apply(array $events, bool $wasFullRead = false, array $instances = []): int
    {
        $touched = $this->puller->apply(
            $this->calendar,
            new CalendarChangeSet($events, instances: $instances),
            $wasFullRead,
        );

        $this->em->flush();

        return $touched;
    }

    /** One of Graph's occurrence resources, as the driver reports it. */
    private function instance(string $remoteId, string $recurrenceId): RemoteInstance
    {
        return new RemoteInstance(
            $remoteId,
            'MASTER',
            new \DateTimeImmutable($recurrenceId, new DateTimeZone('UTC')),
        );
    }

    private function calDav(string $ics): ?RemoteEvent
    {
        return new CalDavEventConverter(new RecurrenceRuleConverter(), new AlertReader(new NullLogger()))
            ->toRemoteEvent($ics, 'https://dav.example.com/c/weekly.ics', '"etag-1"');
    }

    /**
     * @param array<string,mixed> $item
     */
    private function google(array $item): RemoteEvent
    {
        $remote = new GoogleEventMapper(new GoogleRecurrenceMapper(new RecurrenceRuleConverter()), new AlertReader(new NullLogger()))
            ->toRemoteEvent($item, 'Europe/Berlin');

        self::assertNotNull($remote, 'the fixture must be a resource the mapper accepts');

        return $remote;
    }

    /**
     * Graph reports the series and its exceptions through two different halves
     * of its driver, so the mapper is asked for the object and for the original
     * start separately — exactly as GraphCalendarSyncDriver does.
     *
     * @param array<string,mixed> $item
     */
    private function graph(array $item, ?string $seriesRemoteId = null, ?string $originalStart = null): RemoteEvent
    {
        $mapper = new GraphEventMapper(new GraphTimeZoneMapper(), new GraphRecurrenceMapper(), new AlertReader(new NullLogger()));
        $remote = $mapper->toRemoteEvent($item);

        self::assertNotNull($remote);

        if (null === $seriesRemoteId) {
            return $remote;
        }

        $recurrenceId = $mapper->originalStartOf(['originalStart' => $originalStart]);

        self::assertNotNull($recurrenceId);

        return new RemoteEvent(
            remoteId:       $remote->remoteId,
            etag:           $remote->etag,
            uid:            $remote->uid,
            isDeleted:      false,
            jscalendar:     $remote->jscalendar,
            startsAt:       $remote->startsAt,
            endsAt:         $remote->endsAt,
            seriesRemoteId: $seriesRemoteId,
            recurrenceId:   $recurrenceId,
        );
    }

    /** Ten Tuesday standups at 10:00 Berlin, plus whatever else the case needs. */
    private function weeklyIcs(string $extra = ''): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//EN',
            'BEGIN:VEVENT',
            'UID:weekly-1',
            'DTSTAMP:20260801T090000Z',
            'DTSTART;TZID=Europe/Berlin:20260804T100000',
            'DTEND;TZID=Europe/Berlin:20260804T103000',
            'SUMMARY:Standup',
            'RRULE:FREQ=WEEKLY;COUNT=10',
            'END:VEVENT',
        ];

        foreach (explode("\n", trim($extra)) as $line) {
            if ('' !== trim($line)) {
                $lines[] = trim($line);
            }
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @return array<string,mixed>
     */
    private function googleSeries(string $etag = '"etag-1"'): array
    {
        return [
            'id'         => 'ev-1',
            'etag'       => $etag,
            'iCalUID'    => 'uid-1@google.com',
            'status'     => 'confirmed',
            'summary'    => 'Standup',
            'start'      => ['dateTime' => '2026-08-04T10:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
            'end'        => ['dateTime' => '2026-08-04T10:30:00+02:00', 'timeZone' => 'Europe/Berlin'],
            'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=10'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function googleMovedInstance(
        string $id = 'ev-1_20260811T080000Z',
        string $original = '2026-08-11T10:00:00+02:00',
        string $start = '2026-08-11T16:00:00+02:00',
        string $end = '2026-08-11T17:00:00+02:00',
    ): array {
        return [
            'id'      => $id,
            'etag'    => '"etag-i"',
            // Google gives an instance a UID of its own — the series' with the
            // original start appended — which is why a moved occurrence became a
            // second row rather than colliding with the series and being noticed.
            'iCalUID'           => 'uid-1_20260811T080000Z@google.com',
            'status'            => 'confirmed',
            'summary'           => 'Standup',
            'recurringEventId'  => 'ev-1',
            'originalStartTime' => ['dateTime' => $original, 'timeZone' => 'Europe/Berlin'],
            'start'             => ['dateTime' => $start, 'timeZone' => 'Europe/Berlin'],
            'end'               => ['dateTime' => $end, 'timeZone' => 'Europe/Berlin'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function graphSeries(): array
    {
        return [
            'id'                    => 'MASTER',
            'iCalUId'               => 'uid-master',
            'subject'               => 'Standup',
            'type'                  => 'seriesMaster',
            'originalStartTimeZone' => 'W. Europe Standard Time',
            'start'                 => ['dateTime' => '2026-08-04T08:00:00.0000000', 'timeZone' => 'UTC'],
            'end'                   => ['dateTime' => '2026-08-04T08:30:00.0000000', 'timeZone' => 'UTC'],
            'recurrence'            => [
                'pattern' => ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => ['tuesday']],
                'range'   => ['type' => 'numbered', 'numberOfOccurrences' => 10],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function graphException(): array
    {
        return [
            'id'                    => 'OCC3',
            'iCalUId'               => 'uid-master',
            'subject'               => 'Standup (moved)',
            'type'                  => 'exception',
            'seriesMasterId'        => 'MASTER',
            'originalStartTimeZone' => 'W. Europe Standard Time',
            'originalStart'         => '2026-08-11T08:00:00.0000000Z',
            'start'                 => ['dateTime' => '2026-08-11T14:00:00.0000000', 'timeZone' => 'UTC'],
            'end'                   => ['dateTime' => '2026-08-11T15:00:00.0000000', 'timeZone' => 'UTC'],
        ];
    }

    /** A row of the kind a moved instance used to create, to be pruned. */
    private function duplicateRow(string $remoteId): void
    {
        $event             = new CalendarEvent();
        $event->calendar   = $this->calendar;
        $event->usr        = $this->user;
        $event->uid        = 'uid-1_20260811T080000Z@google.com';
        $event->title      = 'Standup';
        $event->remoteId   = $remoteId;
        $event->remoteEtag = '"etag-i"';
        $event->startsAt   = new \DateTimeImmutable('2026-08-11 14:00', new DateTimeZone('UTC'));
        $event->endsAt     = new \DateTimeImmutable('2026-08-11 15:00', new DateTimeZone('UTC'));
        $event->jscalendar = ['@type' => 'Event', 'uid' => $event->uid];

        $this->em->persist($event);
        $this->em->flush();
    }

    /**
     * @return list<string> occurrence starts in Berlin, in order
     */
    private function startsOf(CalendarEvent $event): array
    {
        $starts = [];

        foreach ($event->occurrences as $occurrence) {
            $starts[] = $occurrence->startsAt?->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i');
        }

        sort($starts);

        return $starts;
    }

    private function occurrenceAt(CalendarEvent $event, string $berlinStart): object
    {
        foreach ($event->occurrences as $occurrence) {
            $start = $occurrence->startsAt?->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i');

            if ($start === $berlinStart) {
                return $occurrence;
            }
        }

        self::fail(sprintf('No occurrence starting at %s', $berlinStart));
    }

    private function rowCount(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM calendar_event WHERE calendar_id = ?',
            [$this->calendar->id],
        );
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'puller-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Cal';
        $user->nameLast  = 'Puller';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->name     = 'Work';
        $calendar->role     = CalendarRole::Remote;
        $calendar->remoteId = 'remote-calendar-1';
        $calendar->timeZone = 'Europe/Berlin';
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;
    }
}
