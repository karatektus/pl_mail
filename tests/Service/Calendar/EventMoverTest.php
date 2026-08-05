<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\SyncState;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Entity\User\User;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\EventInstanceEditor;
use App\Service\Calendar\EventMover;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A drag is an edit that posts two fields, and everything it does not post has
 * to survive it.
 *
 * That is the whole claim. The editor's save posts every field and rebuilds the
 * event from them; a drag posts a start and an end, and CalendarEventWriter
 * rebuilds the canonical JSCalendar object from its arguments — so anything the
 * mover forgets to hand back is not left alone, it is erased. Each of these
 * names a way that goes wrong and none of them fails loudly:
 *
 *   A recurrence rule not carried forward turns a weekly standup into a single
 *   meeting and takes every future occurrence off the calendar with it.
 *
 *   A description not carried forward is silently blanked, because it lives
 *   only in the JSCalendar and has no column to survive in.
 *
 *   The dragged block's times written as the series' own rebase the whole
 *   series onto whatever day was clicked. That is the bug seriesTimesFor()
 *   exists for, and a drag is a far easier way to reach it than the editor was
 *   — the editor at least required someone to open it on a later occurrence.
 *
 *   A move that never marks the event is not a small bug on a mirrored
 *   calendar: it is a silent revert, because the next pull finds a remote that
 *   still says the old time and writes it back over the local row.
 *
 * Against a real container and a real database rather than doubles. Every
 * collaborator is final, so none can be doubled; and the claim worth pinning is
 * the one that emerges from all of them together — occurrences re-materialised
 * into the table a calendar view actually reads.
 *
 * Dates are relative to the run. RecurrenceMaterialiser only writes occurrences
 * inside a horizon around now, so a series pinned to a literal year is a suite
 * that passes until that year leaves the window.
 */
final class EventMoverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private EventMover $mover;
    private EventInstanceEditor $instances;
    private CalendarEventWriter $writer;
    private User $user;
    private Calendar $calendar;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->mover      = $container->get(EventMover::class);
        $this->instances  = $container->get(EventInstanceEditor::class);
        $this->writer     = $container->get(CalendarEventWriter::class);

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

    // ── One occurrence ────────────────────────────────────────────────────

    public function testDraggingOneOccurrenceLeavesItsSiblingsWhereTheyWere(): void
    {
        $event = $this->weeklySeries();

        $this->drag($event, 1, '+2 hours', seriesScope: false);

        self::assertSame('11:00', $this->localStart($event, 1), 'the instance that was dragged moved');

        foreach ([0, 2, 3] as $untouched) {
            self::assertSame(
                '09:00',
                $this->localStart($event, $untouched),
                sprintf('occurrence %d was not the one dragged', $untouched),
            );
        }
    }

    /**
     * An instance that was renamed and is then dragged keeps its name.
     *
     * EventInstanceEditor::edit() writes the title it is handed, so a mover that
     * handed it the SERIES' title would file a patch stating this instance is
     * called what the series is called — and the rename would be gone, with the
     * user having done nothing but move the block.
     */
    public function testDraggingARenamedOccurrenceDoesNotUndoTheRename(): void
    {
        $event    = $this->weeklySeries();
        $instance = $this->instanceAt($event, 1);
        $start    = $this->originalStart(1);

        $this->instances->edit($event, $instance, 'Retro', $start, $start->modify('+30 minutes'));
        $this->em->flush();

        $this->drag($event, 1, '+2 hours', seriesScope: false);

        self::assertSame('Retro', $this->instances->titleOf($event, $this->instanceAt($event, 1)));
        self::assertSame('Standup', $event->title, 'and the series is still called what it was');
    }

    // ── The whole series ──────────────────────────────────────────────────

    /**
     * "All events", from a block on the fourth occurrence.
     *
     * The times the grid posts are that occurrence's, so writing them as the
     * series' own start would rebase the series four weeks on. The difference
     * is what is applied — see EventInstanceEditor::seriesTimesFor() — so the
     * series keeps its day and gains the two hours.
     */
    public function testDraggingASeriesFromALaterOccurrenceShiftsItRatherThanRebasingIt(): void
    {
        $event  = $this->weeklySeries();
        $before = $event->startsAt;

        $this->drag($event, 3, '+2 hours', seriesScope: true);

        self::assertSame(
            $before->modify('+2 hours')->format(DATE_ATOM),
            $event->startsAt->format(DATE_ATOM),
            'the series keeps the day it had and moves by what the drag changed',
        );

        // Every occurrence, read straight off the rows rather than through
        // instanceAt(): the whole series moved, so the recurrence ids moved
        // with it and there is no longer an instance at the old 09:00 to look
        // one up by.
        foreach ($event->occurrences as $occurrence) {
            self::assertSame(
                '11:00',
                (string) $occurrence->startsAt?->setTimezone(new DateTimeZone('UTC'))->format('H:i'),
                'every occurrence moved, including the ones before the one that was dragged',
            );
        }
    }

    /**
     * write() states `recurrenceRules` only when it is handed one, so a move
     * that dropped the rule would un-repeat the event — one meeting left, and
     * every other occurrence gone from the calendar with no sign that anything
     * was deleted.
     */
    public function testMovingASeriesLeavesItRepeating(): void
    {
        $event  = $this->weeklySeries();
        $before = count($event->occurrences);

        $this->drag($event, 0, '+2 hours', seriesScope: true);

        self::assertTrue($event->isRecurring, 'it still repeats');
        self::assertCount($before, $event->occurrences, 'and still has every occurrence it had');
        self::assertSame('weekly', $event->jscalendar['recurrenceRules'][0]['frequency']);
    }

    /**
     * The description lives only in the JSCalendar object, which write() builds
     * from its arguments — so a move that did not carry it back would blank it
     * without anything to notice.
     */
    public function testMovingAnEventKeepsTheThingsTheGridHasNoFieldFor(): void
    {
        $event = $this->oneOff(description: 'Bring the numbers', location: 'Room 4');

        $this->mover->move($event, null, true, $this->originalStart(0)->modify('+3 hours'), $this->originalStart(0)->modify('+4 hours'));
        $this->em->flush();

        self::assertSame('Bring the numbers', $event->jscalendar['description']);
        self::assertSame('Room 4', $event->location);
        self::assertSame('Dentist', $event->title);
    }

    /** A one-off has no instance to be about, so "this event" is the series. */
    public function testAOneOffMovesWhicheverScopeIsAskedFor(): void
    {
        $event = $this->oneOff();
        $start = $this->originalStart(0)->modify('+3 hours');

        $this->mover->move($event, null, false, $start, $start->modify('+1 hour'));
        $this->em->flush();

        self::assertSame($start->format(DATE_ATOM), $event->startsAt->format(DATE_ATOM));
    }

    /** Resizing is a move whose start did not change; only the end may. */
    public function testResizingChangesTheLengthAndNotTheStart(): void
    {
        $event = $this->oneOff();
        $start = $this->originalStart(0);

        $this->mover->move($event, null, true, $start, $start->modify('+2 hours'));
        $this->em->flush();

        self::assertSame($start->format(DATE_ATOM), $event->startsAt->format(DATE_ATOM));
        self::assertSame($start->modify('+2 hours')->format(DATE_ATOM), $event->endsAt->format(DATE_ATOM));
    }

    // ── What the remote is told ───────────────────────────────────────────

    /**
     * The case a drag can lose data on. A move that changed the database and
     * queued no push is not a change that failed to reach Google — it is a
     * change that gets reverted, because the next pull finds a remote still
     * holding the old time and writes it back.
     */
    public function testMovingASeriesOnASyncedCalendarQueuesThePush(): void
    {
        $event = $this->weeklySeries(calendar: $this->remoteCalendar());

        self::assertSame(SyncState::Clean, $event->syncState);

        $this->drag($event, 0, '+2 hours', seriesScope: true);

        self::assertSame(SyncState::PendingUpdate, $event->syncState);
    }

    public function testDraggingOneOccurrenceOfASyncedSeriesAlsoQueuesThePush(): void
    {
        $event = $this->weeklySeries(calendar: $this->remoteCalendar());

        $this->drag($event, 1, '+2 hours', seriesScope: false);

        self::assertSame(
            SyncState::PendingUpdate,
            $event->syncState,
            'the master carries the override, so the master owes the remote a write',
        );
    }

    /** A calendar that mirrors nothing has nobody to tell, and must not be queued. */
    public function testMovingOnALocalCalendarQueuesNothing(): void
    {
        $event = $this->weeklySeries();

        $this->drag($event, 0, '+2 hours', seriesScope: true);

        self::assertSame(SyncState::Clean, $event->syncState);
    }

    /**
     * Dragging is a decision, and an extracted event that has been moved by
     * hand must stop being overwritten by the next mail about the same booking.
     * The editor's save marks this; so does a drag, or the two entry points
     * disagree about what counts as the user having had an opinion.
     */
    public function testDraggingAnExtractedEventRecordsThatTheUserTouchedIt(): void
    {
        $event = $this->oneOff();

        // What makes an event extracted is a source link; the flag under test is
        // only ever set for one, which is why the plain fixture below asserts
        // the other direction.
        self::assertFalse($event->isUserEdited);

        $this->drag($event, null, '+2 hours', seriesScope: true);

        self::assertSame(
            $event->isExtracted(),
            $event->isUserEdited,
            'marked exactly when there is something for the mark to protect',
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * Move the nth occurrence of $event by $shift, at the given scope, exactly
     * as the grid would: the times posted are the OCCURRENCE's, not the series'.
     *
     * $index null means an event with no occurrence to name — the one-off case.
     */
    private function drag(CalendarEvent $event, ?int $index, string $shift, bool $seriesScope): void
    {
        $instance = null === $index ? null : $this->instanceAt($event, $index);

        // `??` without `?->`: the coalesce already answers for a null instance,
        // and the nullsafe in front of it is the redundancy PHPStan refuses.
        $startsAt = ($instance->startsAt ?? $event->startsAt)->modify($shift);
        $endsAt   = ($instance->endsAt ?? $event->endsAt)->modify($shift);

        $this->mover->move($event, $instance, $seriesScope, $startsAt, $endsAt);

        $this->em->flush();
    }

    /**
     * A weekly 09:00 series of eight, starting the Monday after the run. Next
     * week so it is always inside RecurrenceMaterialiser's horizon, and Monday
     * so the eight instances are eight distinct days whichever day this runs on.
     */
    private function weeklySeries(?Calendar $calendar = null): CalendarEvent
    {
        $start = $this->originalStart(0);

        $event = $this->writer->write(
            event:          new CalendarEvent(),
            calendar:       $calendar ?? $this->calendar,
            user:           $this->user,
            title:          'Standup',
            startsAt:       $start,
            endsAt:         $start->modify('+30 minutes'),
            timeZone:       'UTC',
            recurrenceRule: ['@type' => 'RecurrenceRule', 'frequency' => 'weekly', 'count' => 8],
        );

        $this->em->flush();

        return $event;
    }

    private function oneOff(?string $description = null, ?string $location = null): CalendarEvent
    {
        $start = $this->originalStart(0);

        $event = $this->writer->write(
            event:       new CalendarEvent(),
            calendar:    $this->calendar,
            user:        $this->user,
            title:       'Dentist',
            startsAt:    $start,
            endsAt:      $start->modify('+1 hour'),
            timeZone:    'UTC',
            location:    $location,
            description: $description,
        );

        $this->em->flush();

        return $event;
    }

    /** Where the rule puts the nth instance, before anything moves it. */
    private function originalStart(int $index): DateTimeImmutable
    {
        return new DateTimeImmutable('monday next week 09:00', new DateTimeZone('UTC'))
            ->modify(sprintf('+%d weeks', $index));
    }

    /**
     * The nth instance, named by its recurrence id — where the rule put it, not
     * where it is now. Ordering on the current start would renumber the series
     * the moment one instance was moved past another.
     */
    private function instanceAt(CalendarEvent $event, int $index): CalendarEventOccurrence
    {
        $wanted = $this->originalStart($index)->format(DATE_ATOM);

        foreach ($event->occurrences as $occurrence) {
            if ($occurrence->recurrenceId?->format(DATE_ATOM) === $wanted) {
                return $occurrence;
            }
        }

        self::fail(sprintf('the series should have an instance %d weeks in', $index));
    }

    /** Where one instance actually is, on the clock the series is read by. */
    private function localStart(CalendarEvent $event, int $index): string
    {
        return (string) $this->instanceAt($event, $index)
            ->startsAt?->setTimezone(new DateTimeZone('UTC'))->format('H:i');
    }

    private function remoteCalendar(): Calendar
    {
        $calendar           = new Calendar();
        $calendar->usr      = $this->user;
        $calendar->name     = 'Mirrored';
        $calendar->role     = CalendarRole::Remote;
        $calendar->timeZone = 'UTC';
        $calendar->remoteId = 'remote-' . uniqid('', true);
        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'mover-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Mover';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->name     = 'Mover fixture';
        $calendar->role     = CalendarRole::Custom;
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;
    }
}
