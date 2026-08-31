<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Calendar;

use App\Jmap\Method\Calendar\CalendarChangesMethod;
use App\Jmap\Method\Calendar\CalendarGetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use DateTimeImmutable;

/**
 * Calendar/changes, and the separation that makes it mean anything.
 *
 * One table records both kinds of change and every reader filters: rows with an
 * event are what happened inside a collection, rows without are what happened to
 * the collection itself. The filter is the load-bearing part. Without it a
 * renamed calendar would be reported to CalendarEvent/changes as a changed
 * event, and a CalDAV client would build an href out of a null UID — so the
 * tests here assert both that each method sees its own kind and that it does not
 * see the other's.
 *
 * The last claim is about Calendar/get, which returned a constant until this
 * landed. A state that never moves is a promise that nothing changed, and a
 * client is entitled to believe it.
 */
final class CalendarChangesMethodTest extends CalendarMethodTestCase
{
    private CalendarChangesMethod $method;
    private CalendarGetMethod $get;

    protected function setUp(): void
    {
        parent::setUp();

        $this->method = self::getContainer()->get(CalendarChangesMethod::class);
        $this->get    = self::getContainer()->get(CalendarGetMethod::class);
    }

    public function testACreatedCalendarIsReported(): void
    {
        $since = $this->changes('0')['newState'];

        $calendar = $this->seedCalendar('Work');

        self::assertSame([(string) $calendar->id], $this->changes($since)['created']);
    }

    public function testARenameIsReportedAsUpdated(): void
    {
        $calendar = $this->seedCalendar('Work');
        $since    = $this->changes('0')['newState'];

        $calendar->name = 'Work (renamed)';
        $this->em->flush();

        $result = $this->changes($since);

        self::assertSame([], $result['created']);
        self::assertSame([(string) $calendar->id], $result['updated']);
    }

    public function testARecolourIsReportedBecauseAClientDrawsIt(): void
    {
        $calendar = $this->seedCalendar('Work');
        $since    = $this->changes('0')['newState'];

        $calendar->color = '#ff0066';
        $this->em->flush();

        self::assertSame([(string) $calendar->id], $this->changes($since)['updated']);
    }

    /**
     * Sync plumbing is not a change anyone can see, and recording it would wake
     * every client each time a mirror was polled.
     */
    public function testSyncBookkeepingOnACalendarDoesNotMoveTheToken(): void
    {
        $calendar = $this->seedCalendar('Work');
        $since    = $this->changes('0')['newState'];

        $calendar->lastSyncedAt = new DateTimeImmutable();
        $calendar->syncToken    = 'opaque-token-from-the-remote';
        $this->em->flush();

        self::assertSame($since, $this->changes($since)['newState'], 'the token must not move');
    }

    public function testADeletedCalendarIsReportedAsDestroyed(): void
    {
        $calendar = $this->seedCalendar('Doomed');
        $id       = (string) $calendar->id;
        $since    = $this->changes('0')['newState'];

        $this->em->remove($calendar);
        $this->em->flush();

        self::assertSame([$id], $this->changes($since)['destroyed']);
    }

    /** The filter, from this side: an event is not a change to its calendar. */
    public function testAnEventChangeIsNotReportedHere(): void
    {
        $calendar = $this->seedCalendar('Work');
        $since    = $this->changes('0')['newState'];

        $this->seedEvent($calendar, 'Kickoff', $this->baseDay());

        $result = $this->changes($since);

        self::assertSame([], $result['created']);
        self::assertSame([], $result['updated']);
        self::assertSame([], $result['destroyed']);
    }

    /** And from the other side: a rename is not a changed event. */
    public function testACalendarChangeIsNotReportedAsAnEventChange(): void
    {
        $calendar = $this->seedCalendar('Work');
        $this->seedEvent($calendar, 'Kickoff', $this->baseDay());

        $events = self::getContainer()->get(\App\Jmap\Method\Calendar\CalendarEventChangesMethod::class);
        $since  = $events->handle(
            ['accountId' => $this->accountId(), 'sinceState' => '0'],
            $this->context(),
        )['newState'];

        $calendar->name = 'Renamed';
        $this->em->flush();

        $result = $events->handle(
            ['accountId' => $this->accountId(), 'sinceState' => $since],
            $this->context(),
        );

        self::assertSame([], $result['created']);
        self::assertSame([], $result['updated']);
        self::assertSame([], $result['destroyed']);
    }

    /** Calendar/get used to answer "fixed" whatever had happened. */
    public function testCalendarGetReturnsAStateThatActuallyMoves(): void
    {
        $calendar = $this->seedCalendar('Work');

        $before = $this->state();

        $calendar->name = 'Moved';
        $this->em->flush();

        self::assertNotSame($before, $this->state(), 'a rename must change the state Calendar/get reports');
    }

    public function testAnUnreadableTokenIsRefused(): void
    {
        $this->expectException(MethodException::class);

        $this->changes('not-a-sequence');
    }

    /**
     * @return array<string,mixed>
     */
    private function changes(string $sinceState): array
    {
        return $this->method->handle(
            ['accountId' => $this->accountId(), 'sinceState' => $sinceState],
            $this->context(),
        );
    }

    private function state(): string
    {
        return $this->get->handle(['accountId' => $this->accountId()], $this->context())['state'];
    }
}
