<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\SyncState;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Jmap\Method\Calendar\CalendarEventSetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Repository\Calendar\CalendarEventRepository;
use DateTimeImmutable;

/**
 * A write from a JMAP client is the same write the web editor makes.
 *
 * Three claims, each of which fails silently if it is wrong.
 *
 * **It goes through CalendarEventWriter.** An event is a canonical JSCalendar
 * object plus columns projected from it plus the occurrence rows a view reads,
 * and only the writer produces all three. A /set that assigned columns would
 * insert a row that looks correct in the database and appears on no calendar at
 * all — so the assertions below are about the occurrences, not about the row.
 *
 * **A read-only calendar refuses.** Accepting the edit and letting CalendarPusher
 * discard it later would leave a client that was told "updated" showing an event
 * the next pull silently reverts.
 *
 * **A synced event is marked for push.** The writer deliberately does not mark
 * inside write(), because write() is also how a pull applies what it just read;
 * so every caller that is a person marks, and a JMAP client is a person. Without
 * it an edit made on a phone never leaves the machine and is reverted by the
 * next pull fifteen minutes later.
 */
final class CalendarEventSetMethodTest extends CalendarMethodTestCase
{
    private CalendarEventSetMethod $method;
    private CalendarEventRepository $events;

    private Calendar $calendar;
    private DateTimeImmutable $day;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $this->method = $container->get(CalendarEventSetMethod::class);
        $this->events = $container->get(CalendarEventRepository::class);

        $this->day = $this->baseDay();
        $this->calendar = $this->seedCalendar('Work');
    }

    public function testACreatedEventHasTheOccurrenceRowAViewReads(): void
    {
        $event = $this->createdEvent(['title' => 'Kickoff', 'start' => $this->local(9)]);

        self::assertSame(1, $this->occurrenceCount($event), 'an event with no occurrence row is on no calendar');
    }

    /**
     * A recurring create materialises the whole series, not one row: the
     * occurrences are what a view reads, so a series that produced a single row
     * would draw one meeting and lose the rest without failing anything.
     */
    public function testACreatedSeriesMaterialisesEveryOccurrence(): void
    {
        $event = $this->createdEvent([
            'title' => 'Standup',
            'start' => $this->local(9),
            'duration' => 'PT15M',
            'recurrenceRules' => [['@type' => 'RecurrenceRule', 'frequency' => 'weekly', 'count' => 4]],
        ]);

        self::assertTrue($event->isRecurring);
        self::assertSame(4, $this->occurrenceCount($event));
    }

    /**
     * The canonical object and the columns say the same thing, which is the
     * whole reason the writer is the only way in. A create that set $title
     * alone would export a blank .ics and read correctly everywhere else.
     */
    public function testTheCanonicalObjectAndTheColumnsAgree(): void
    {
        $event = $this->createdEvent([
            'title' => 'Kickoff',
            'start' => $this->local(9),
            'locations' => ['1' => ['@type' => 'Location', 'name' => 'Room 2']],
        ]);

        self::assertSame('Kickoff', $event->title);
        self::assertSame('Kickoff', $event->jscalendar['title']);
        self::assertSame('Room 2', $event->location);
        self::assertSame('Room 2', $event->jscalendar['locations']['1']['name']);
    }

    /** A client that supplied no uid gets the one every other calendar will match this event on. */
    public function testACreateWithoutAUidIsGivenOne(): void
    {
        $created = $this->create(['new' => ['calendarId' => (string) $this->calendar->id, 'title' => 'Kickoff', 'start' => $this->local(9)]]);

        self::assertNotSame('', $created['created']['new']['uid']);
    }

    public function testAReadOnlyCalendarRefusesACreate(): void
    {
        $mirror = $this->seedCalendar('Mirror', true);

        $result = $this->create(['new' => [
            'calendarId' => (string) $mirror->id,
            'title' => 'Not mine to write',
            'start' => $this->local(9),
        ]]);

        self::assertSame([], $result['created'] instanceof \stdClass ? [] : $result['created']);
        self::assertSame('forbidden', $result['notCreated']['new']['type']);
        self::assertSame([], $this->events->findByUidForUser($this->user, 'not-mine-to-write'), 'nothing may be written');
    }

    public function testAReadOnlyCalendarRefusesAnUpdate(): void
    {
        $mirror = $this->seedCalendar('Mirror', true);
        $event = $this->seedEvent($mirror, 'Mirrored meeting', $this->day->setTime(9, 0));

        $result = $this->method->handle([
            'accountId' => $this->accountId(),
            'update' => [(string) $event->id => ['title' => 'Renamed']],
        ], $this->context());

        self::assertSame('forbidden', $result['notUpdated'][(string) $event->id]['type']);
        self::assertSame('Mirrored meeting', $event->title);
    }

    public function testAReadOnlyCalendarRefusesADestroy(): void
    {
        $mirror = $this->seedCalendar('Mirror', true);
        $event = $this->seedEvent($mirror, 'Mirrored meeting', $this->day->setTime(9, 0));

        $result = $this->method->handle([
            'accountId' => $this->accountId(),
            'destroy' => [(string) $event->id],
        ], $this->context());

        self::assertSame([], $result['destroyed']);
        self::assertSame('forbidden', $result['notDestroyed'][(string) $event->id]['type']);
        self::assertNotNull($this->events->findOneForUser($this->user, (int) $event->id));
    }

    /**
     * Somebody else's event is missing, not refused. notFound and forbidden are
     * distinguishable to a client, and only one of them confirms the id exists.
     */
    public function testAnotherUsersEventIsInvisibleRatherThanAnError(): void
    {
        $theirs = $this->seedCalendar('Theirs', false, $this->otherUser());
        $event = $this->seedEvent($theirs, 'Their meeting', $this->day->setTime(9, 0));

        $result = $this->method->handle([
            'accountId' => $this->accountId(),
            'update' => [(string) $event->id => ['title' => 'Renamed']],
        ], $this->context());

        self::assertSame('notFound', $result['notUpdated'][(string) $event->id]['type']);
        self::assertSame('Their meeting', $event->title, 'their event must be untouched');
    }

    /**
     * An edit that is never marked is an edit that never leaves: the pusher
     * sweeps on this column alone, and the next pull would overwrite the row
     * with the remote's older copy.
     */
    public function testAnEditOnASyncedCalendarIsMarkedForPush(): void
    {
        $mirrored = $this->mirroredCalendar();
        $event = $this->seedEvent($mirrored, 'Mirrored meeting', $this->day->setTime(9, 0));
        $event->syncState = SyncState::Clean;
        $this->em->flush();

        $this->method->handle([
            'accountId' => $this->accountId(),
            'update' => [(string) $event->id => ['title' => 'Moved to Thursday']],
        ], $this->context());

        self::assertSame(SyncState::PendingUpdate, $event->syncState);
    }

    /**
     * A create is a POST at the remote and an update is a PUT, and the state
     * alone cannot tell them apart — a brand-new event is Clean, exactly like
     * one in step with the remote. Marking a create as an update would push an
     * update for a resource the remote has never heard of.
     */
    public function testACreateOnASyncedCalendarIsMarkedAsACreate(): void
    {
        $mirrored = $this->mirroredCalendar();

        $event = $this->createdEvent(['title' => 'Kickoff', 'start' => $this->local(9)], $mirrored);

        self::assertSame(SyncState::PendingCreate, $event->syncState);
    }

    /** An event on a calendar that mirrors nothing owes nobody a write, and must not be queued for one. */
    public function testAnEditOnALocalCalendarIsNotMarkedForPush(): void
    {
        $event = $this->createdEvent(['title' => 'Kickoff', 'start' => $this->local(9)]);

        self::assertSame(SyncState::Clean, $event->syncState);
    }

    /**
     * A property this server cannot store is refused by name. Dropping it
     * quietly would tell a client it had invited somebody, with no way for it
     * to discover otherwise.
     */
    public function testAPropertyThatCannotBeStoredIsRefusedRatherThanDropped(): void
    {
        $result = $this->create(['new' => [
            'calendarId' => (string) $this->calendar->id,
            'title' => 'Kickoff',
            'start' => $this->local(9),
            'participants' => ['someone@example.test' => ['@type' => 'Participant']],
        ]]);

        self::assertSame('invalidProperties', $result['notCreated']['new']['type']);
        self::assertSame(['participants'], $result['notCreated']['new']['properties']);
    }

    /**
     * A start carrying a zone is refused rather than reinterpreted. JSCalendar's
     * LocalDateTime has no offset; a trailing Z says UTC while `timeZone` says
     * something else, and guessing between them moves a meeting by hours in
     * silence.
     */
    public function testAStartWithAZoneIsRefusedRatherThanGuessedAt(): void
    {
        $result = $this->create(['new' => [
            'calendarId' => (string) $this->calendar->id,
            'title' => 'Kickoff',
            'start' => $this->day->setTime(9, 0)->format('Y-m-d\TH:i:s\Z'),
        ]]);

        self::assertSame('invalidProperties', $result['notCreated']['new']['type']);
    }

    /**
     * An update names only what it changes; everything else is re-supplied from
     * what is stored. The writer derives the canonical object from its
     * arguments, so a missing description passed through as null would delete
     * one the client never mentioned.
     */
    public function testAnUpdateLeavesTheThingsItDidNotName(): void
    {
        $event = $this->createdEvent([
            'title' => 'Kickoff',
            'start' => $this->local(9),
            'description' => 'Bring the deck',
            'locations' => ['1' => ['@type' => 'Location', 'name' => 'Room 2']],
        ]);

        $this->method->handle([
            'accountId' => $this->accountId(),
            'update' => [(string) $event->id => ['title' => 'Kickoff (moved)']],
        ], $this->context());

        self::assertSame('Kickoff (moved)', $event->title);
        self::assertSame('Bring the deck', $event->jscalendar['description']);
        self::assertSame('Room 2', $event->location);
    }

    /** ifInState could only ever answer "nothing has changed", so it is refused rather than believed. */
    public function testIfInStateIsRefusedRatherThanAlwaysSatisfied(): void
    {
        $this->expectException(MethodException::class);

        $this->method->handle([
            'accountId' => $this->accountId(),
            'ifInState' => 'fixed',
            'destroy' => [],
        ], $this->context());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $create
     *
     * @return array<string,mixed>
     */
    private function create(array $create): array
    {
        return $this->method->handle([
            'accountId' => $this->accountId(),
            'create' => $create,
        ], $this->context());
    }

    /**
     * @param array<string,mixed> $properties
     */
    private function createdEvent(array $properties, ?Calendar $calendar = null): CalendarEvent
    {
        $properties['calendarId'] = (string) ($calendar ?? $this->calendar)->id;

        $result = $this->create(['new' => $properties]);

        self::assertArrayNotHasKey('new', $result['notCreated'] instanceof \stdClass ? [] : $result['notCreated'], 'the create was refused');

        $event = $this->events->findOneForUser($this->user, (int) $result['created']['new']['id']);

        self::assertInstanceOf(CalendarEvent::class, $event);

        return $event;
    }

    /** A calendar that genuinely mirrors a remote — role and remoteId both, which is what isSynced() asks. */
    private function mirroredCalendar(): Calendar
    {
        $calendar = $this->seedCalendar('Mirrored');
        $calendar->role = CalendarRole::Remote;
        $calendar->remoteId = 'remote-'.uniqid('', true);
        $this->em->flush();

        return $calendar;
    }

    /** A JSCalendar LocalDateTime on the fixture's day. */
    private function local(int $hour): string
    {
        return $this->day->setTime($hour, 0)->format('Y-m-d\TH:i:s');
    }
}
