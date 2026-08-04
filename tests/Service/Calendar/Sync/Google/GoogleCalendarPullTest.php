<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\Google;

use App\Domain\DTO\Calendar\RemoteEvent;
use App\Service\Calendar\Sync\Google\GoogleRecurrenceMapper;
use PHPUnit\Framework\TestCase;

/**
 * A Google event resource becomes JSCalendar, and one delta window becomes one
 * change set.
 *
 * The claim is that everything the engine is ever told about a Google calendar
 * passes through this mapping — so every fact it loses is lost for good, and
 * every fact it invents is a row nobody can explain. The cases below are the
 * five ways that goes wrong in practice:
 *
 *   An all-day event mapped as a timed one at midnight in some zone, which puts
 *   a birthday on the wrong day for everybody an hour to the west.
 *
 *   A cancellation mapped as a cancelled *event* rather than as a deletion,
 *   which leaves a row with no title and no time on the calendar forever —
 *   Google sends nothing but an id and a status in that case.
 *
 *   Paging returned to the engine a page at a time, or `nextPageToken`
 *   returned where `nextSyncToken` belongs, so the delta feed silently stops
 *   halfway and the calendar never catches up.
 *
 *   A tombstone in a full read, which the engine trusts as authoritative and
 *   which therefore deletes a local row nothing asked it to.
 *
 *   The organiser overwritten by their own attendee line, so an event ends up
 *   with nobody holding the `owner` role — the exact bug that was just fixed in
 *   IcsEventExtractor::participantsOf() and that this mapping is structured to
 *   avoid rather than to rediscover.
 *
 * Against MockHttpClient rather than a doubled client, because the driver's
 * collaborators are final and because half the claims here are about the
 * request rather than the response: which query the first read sends, and what
 * changes once there is a sync token.
 */
final class GoogleCalendarPullTest extends TestCase
{
    public function testATimedEventKeepsItsInstantAndItsOwnWallClock(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items'         => [$this->timedEvent()],
            'nextSyncToken' => 'token-1',
        ]));

        $changes = $fixture->driver->pull(GoogleDriverFixture::calendar(), null);

        self::assertCount(1, $changes->events);
        self::assertSame('token-1', $changes->nextSyncToken);

        $event = $changes->events[0];

        self::assertSame('ev-1', $event->remoteId);
        self::assertSame('uid-1@google.com', $event->uid, 'the iCalUID is the identity a mailed invite shares');
        self::assertSame('"3181"', $event->etag);
        self::assertFalse($event->isDeleted);

        // The offset in dateTime fixes the instant; UTC is what crosses the
        // driver boundary and what the columns are.
        self::assertSame('2026-08-04T08:00:00+00:00', $event->startsAt?->format('c'));
        self::assertSame('2026-08-04T08:30:00+00:00', $event->endsAt?->format('c'));

        // JSCalendar times are LocalDateTime — no offset, no trailing Z — with
        // timeZone naming the zone they are local to.
        self::assertSame('2026-08-04T10:00:00', $event->jscalendar['start'] ?? null);
        self::assertSame('Europe/Berlin', $event->jscalendar['timeZone'] ?? null);
        self::assertSame('PT30M', $event->jscalendar['duration'] ?? null);
        self::assertSame('Standup', $event->jscalendar['title'] ?? null);
        self::assertSame('Daily, briefly', $event->jscalendar['description'] ?? null);
        self::assertSame('confirmed', $event->jscalendar['status'] ?? null);
        self::assertSame(
            [['@type' => 'Location', 'name' => 'Room 3']],
            array_values((array) ($event->jscalendar['locations'] ?? [])),
        );
        self::assertArrayNotHasKey('showWithoutTime', $event->jscalendar ?? []);
    }

    public function testAnAllDayEventIsFloatingAndKeepsItsExclusiveEnd(): void
    {
        // Mapped as a timed event at midnight in some zone, a birthday lands on
        // the wrong day for everybody an hour to the west of it.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [[
                'id'      => 'ev-2',
                'etag'    => '"1"',
                'iCalUID' => 'uid-2@google.com',
                'status'  => 'confirmed',
                'summary' => 'Between the years',
                'start'   => ['date' => '2026-12-24'],
                'end'     => ['date' => '2026-12-27'],
            ]],
        ]));

        $event = $fixture->driver->pull(GoogleDriverFixture::calendar(), null)->events[0];

        self::assertSame('2026-12-24T00:00:00+00:00', $event->startsAt?->format('c'));
        self::assertSame('2026-12-27T00:00:00+00:00', $event->endsAt?->format('c'), 'end.date is exclusive on both sides');
        self::assertTrue($event->jscalendar['showWithoutTime'] ?? false);
        self::assertSame('P3D', $event->jscalendar['duration'] ?? null);
        self::assertSame('2026-12-24T00:00:00', $event->jscalendar['start'] ?? null);
        self::assertArrayNotHasKey(
            'timeZone',
            $event->jscalendar ?? [],
            'an all-day event is floating; giving it a zone is what moves it a day',
        );
    }

    public function testASingleDayAllDayEventGetsTheDayItsMissingEndImplies(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [[
                'id'     => 'ev-2',
                'status' => 'confirmed',
                'start'  => ['date' => '2026-12-24'],
            ]],
        ]));

        $event = $fixture->driver->pull(GoogleDriverFixture::calendar(), null)->events[0];

        self::assertSame('2026-12-25T00:00:00+00:00', $event->endsAt?->format('c'));
        self::assertSame('P1D', $event->jscalendar['duration'] ?? null);
    }

    public function testACancelledEventIsATombstoneRatherThanAnEventWithNoTimes(): void
    {
        // All Google sends for a removal is the id and the status. Mapped as a
        // cancelled event it would be a row with no title and no time, sitting
        // on the calendar for good.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items'         => [['id' => 'ev-3', 'status' => 'cancelled']],
            'nextSyncToken' => 'token-2',
        ]));

        $event = $fixture->driver->pull(GoogleDriverFixture::calendar(), 'token-1')->events[0];

        self::assertTrue($event->isDeleted);
        self::assertSame('ev-3', $event->remoteId);
        self::assertSame('ev-3', $event->uid, 'a cancellation carries no iCalUID, so the id stands in');
        self::assertNull($event->jscalendar);
        self::assertNull($event->startsAt);
    }

    public function testTwoPagesBecomeOneWindowEndingAtTheSyncToken(): void
    {
        // nextSyncToken arrives only on the last page. Returning the page
        // cursor in its place is how a delta feed stops halfway and never
        // catches up, because the next run resumes from the middle of a window
        // it has already applied.
        $fixture = new GoogleDriverFixture(
            GoogleDriverFixture::json([
                'items'         => [$this->timedEvent('ev-1', 'uid-1@google.com')],
                'nextPageToken' => 'page-2',
            ]),
            GoogleDriverFixture::json([
                'items'         => [$this->timedEvent('ev-2', 'uid-2@google.com')],
                'nextSyncToken' => 'token-9',
            ]),
        );

        $changes = $fixture->driver->pull(GoogleDriverFixture::calendar(), 'token-8');

        self::assertSame(['ev-1', 'ev-2'], array_map(
            static fn (RemoteEvent $event): string => $event->remoteId,
            $changes->events,
        ));
        self::assertSame('token-9', $changes->nextSyncToken, 'the token is the end of the window, not a page cursor');

        self::assertSame('page-2', $this->queryOf($fixture->url(1))['pageToken'] ?? null);
        self::assertSame(
            'token-8',
            $this->queryOf($fixture->url(1))['syncToken'] ?? null,
            'every page of one window is asked for from the same position',
        );
    }

    public function testAPageCursorIsNeverHandedOverAsTheSyncPosition(): void
    {
        // The last page of this window carries no nextSyncToken — which
        // happens, and is what CalendarChangeSet's null means: the engine keeps
        // the token it had. Handing the page cursor over instead stores a
        // cursor in Calendar::$syncToken, and the next poll resumes from the
        // middle of a window that has already been applied.
        $fixture = new GoogleDriverFixture(
            GoogleDriverFixture::json(['items' => [], 'nextPageToken' => 'page-2']),
            GoogleDriverFixture::json(['items' => []]),
        );

        $changes = $fixture->driver->pull(GoogleDriverFixture::calendar(), 'token-8');

        self::assertNull($changes->nextSyncToken);
    }

    public function testAFirstReadIsBoundedAndAsksForNothingDeleted(): void
    {
        // An unbounded first read of a decade-old calendar is dozens of pages
        // and a table full of meetings from 2016. And a full read must carry no
        // tombstones: the engine treats it as authoritative.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json(['items' => []]));

        $fixture->driver->pull(GoogleDriverFixture::calendar(), null);

        $query = $this->queryOf($fixture->url(0));

        self::assertArrayHasKey('timeMin', $query, 'the first read is bounded');
        self::assertArrayNotHasKey('syncToken', $query);
        self::assertSame('false', $query['showDeleted'] ?? null);
        self::assertSame('false', $query['singleEvents'] ?? null, 'series, not instances');

        $timeMin = new \DateTimeImmutable((string) $query['timeMin']);
        $lower   = new \DateTimeImmutable('-1 year -1 day');
        $upper   = new \DateTimeImmutable('-1 year +1 day');

        self::assertGreaterThan($lower, $timeMin);
        self::assertLessThan($upper, $timeMin);
    }

    public function testASyncTokenReplacesTheWindowRatherThanJoiningIt(): void
    {
        // Google rejects timeMin and syncToken together outright, so an
        // incremental read that kept the window would 400 on every poll.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json(['items' => []]));

        $fixture->driver->pull(GoogleDriverFixture::calendar(), 'token-1');

        $query = $this->queryOf($fixture->url(0));

        self::assertSame('token-1', $query['syncToken'] ?? null);
        self::assertArrayNotHasKey('timeMin', $query);
        self::assertSame('true', $query['showDeleted'] ?? null, 'a delta window is where removals live');
    }

    public function testAFullReadNeverReportsATombstone(): void
    {
        // The engine treats a full read as authoritative and removes local rows
        // the listing did not mention, so a tombstone in one is at best noise.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [
                ['id' => 'ev-3', 'status' => 'cancelled'],
                $this->timedEvent(),
            ],
        ]));

        $changes = $fixture->driver->pull(GoogleDriverFixture::calendar(), null);

        self::assertCount(1, $changes->events);
        self::assertSame('ev-1', $changes->events[0]->remoteId);
    }

    public function testAFullReadStillReportsACancelledInstanceOfALiveSeries(): void
    {
        // The one deletion a full read has to carry, and the reason the filter
        // above asks what kind it is. A cancelled instance is not a tombstone
        // against a row — an instance has never been one — it is the only
        // statement anywhere that one occurrence of a series that is still there
        // is off. Google sends them even with showDeleted=false while
        // singleEvents is false, which is exactly the shape this sync uses;
        // dropped, a full resync resurrects every instance the user ever
        // cancelled.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [
                [
                    'id'                => 'ev-1_20260811T080000Z',
                    'status'            => 'cancelled',
                    'recurringEventId'  => 'ev-1',
                    'originalStartTime' => ['dateTime' => '2026-08-11T10:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
                ],
                $this->timedEvent(),
            ],
        ]));

        $changes = $fixture->driver->pull(GoogleDriverFixture::calendar(), null);

        self::assertCount(2, $changes->events);

        $cancelled = $changes->events[0];

        self::assertTrue($cancelled->isDeleted);
        self::assertTrue($cancelled->isSeriesInstance(), 'the series is alive; one instance of it is not');
        self::assertSame('ev-1', $cancelled->seriesRemoteId);
        self::assertSame('2026-08-11T08:00:00+00:00', $cancelled->recurrenceId?->format('c'));
    }

    public function testAMovedInstanceIsAnOverrideOnItsSeriesRatherThanASecondEvent(): void
    {
        // Written through as an event of its own — which is what happened
        // before — this is a duplicate on the day it moved to, sitting beside a
        // series that still draws it on the day it left.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [array_merge($this->timedEvent('ev-1_20260811T080000Z', 'uid-1@google.com'), [
                'recurringEventId'  => 'ev-1',
                'originalStartTime' => ['dateTime' => '2026-08-11T10:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
                'start'             => ['dateTime' => '2026-08-11T16:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
                'end'               => ['dateTime' => '2026-08-11T17:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
            ])],
        ]));

        $instance = $fixture->driver->pull(GoogleDriverFixture::calendar(), null)->events[0];

        self::assertTrue($instance->isSeriesInstance());
        self::assertSame('ev-1', $instance->seriesRemoteId);

        // The ORIGINAL start, not the one it was dragged to: it is the only name
        // the instance keeps, and the key the expander looks the patch up by.
        self::assertSame('2026-08-11T08:00:00+00:00', $instance->recurrenceId?->format('c'));
        self::assertSame('2026-08-11T14:00:00+00:00', $instance->startsAt?->format('c'));
        self::assertSame('PT1H', $instance->jscalendar['duration'] ?? null);
    }

    public function testAnInstanceGoogleGaveNoOriginalStartForStaysAnEventRatherThanAPatchOnNothing(): void
    {
        // A patch with no key is a patch on nothing, and would lose the instance
        // altogether. The duplicate row that behaviour used to produce is the
        // lesser of the two, and it is at least visible.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [array_merge($this->timedEvent(), ['recurringEventId' => 'ev-0'])],
        ]));

        $instance = $fixture->driver->pull(GoogleDriverFixture::calendar(), null)->events[0];

        self::assertFalse($instance->isSeriesInstance());
        self::assertNull($instance->seriesRemoteId);
    }

    public function testAnOrganiserWhoIsAlsoAnAttendeeKeepsBothRoles(): void
    {
        // Google sends the organiser twice for a meeting its owner is going to,
        // and only the second mention carries the RSVP. Written over the first,
        // the event ends up with nobody holding `owner`, and the invite card
        // has nobody to answer for.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [$this->timedEvent()],
        ]));

        $participants = $fixture->driver->pull(GoogleDriverFixture::calendar(), null)
            ->events[0]
            ->jscalendar['participants'] ?? [];

        self::assertSame(['alice@example.com', 'bob@example.com'], array_keys($participants));

        $alice = $participants['alice@example.com'];

        self::assertSame(['owner' => true, 'attendee' => true], $alice['roles']);
        self::assertSame('accepted', $alice['participationStatus'], 'the organiser answers on their attendee line');
        self::assertSame('Alice', $alice['name']);
        self::assertSame('Alice@Example.com', $alice['email'], 'the address is the identity, case and all');

        $bob = $participants['bob@example.com'];

        self::assertSame(['attendee' => true, 'optional' => true], $bob['roles']);
        self::assertSame('needs-action', $bob['participationStatus']);
    }

    public function testARoomIsAParticipantOfADifferentKind(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [array_merge($this->timedEvent(), ['attendees' => [
                ['email' => 'room-3@resource.calendar.google.com', 'resource' => true, 'responseStatus' => 'accepted'],
            ]])],
        ]));

        $participants = $fixture->driver->pull(GoogleDriverFixture::calendar(), null)
            ->events[0]
            ->jscalendar['participants'] ?? [];

        self::assertSame('resource', $participants['room-3@resource.calendar.google.com']['kind'] ?? null);
    }

    public function testARecurringEventArrivesAsARuleRatherThanAsItsInstances(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [$this->timedEvent() + ['recurrence' => [
                'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,-1FR;UNTIL=20261231T215959Z',
                'EXDATE;TZID=Europe/Berlin:20260817T100000',
            ]]],
        ]));

        $jscalendar = $fixture->driver->pull(GoogleDriverFixture::calendar(), null)->events[0]->jscalendar ?? [];

        self::assertSame([[
            '@type'     => 'RecurrenceRule',
            'frequency' => 'weekly',
            'interval'  => 2,
            'until'     => '2026-12-31T22:59:59',
            'byDay'     => [
                ['@type' => 'NDay', 'day' => 'mo'],
                ['@type' => 'NDay', 'day' => 'fr', 'nthOfPeriod' => -1],
            ],
        ]], $jscalendar['recurrenceRules'] ?? null);

        // UNTIL is a UTC instant at Google and a LocalDateTime in JSCalendar,
        // read in the event's own zone. Carried across unconverted it would end
        // a Berlin series two hours early — visible only as a missing last
        // occurrence, which is the kind of bug nobody reports.
        self::assertSame(
            ['EXDATE;TZID=Europe/Berlin:20260817T100000'],
            $jscalendar[GoogleRecurrenceMapper::PRESERVED_LINES] ?? null,
            'the exclusions have to survive the round trip, or a push resurrects them',
        );
    }

    public function testARuleTheExpanderCannotHonourIsDroppedRatherThanHalfConverted(): void
    {
        // SECONDLY is legal in RFC 5545 and sabre's iterator accepts it, then
        // never advances — the occurrence cap turns that from a hang into a
        // thousand identical rows, which looks like it worked. An event that
        // does not recur is visibly wrong instead of quietly wrong.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [array_merge($this->timedEvent(), ['recurrence' => ['RRULE:FREQ=SECONDLY;COUNT=5']])],
        ]));

        $jscalendar = $fixture->driver->pull(GoogleDriverFixture::calendar(), null)->events[0]->jscalendar ?? [];

        self::assertArrayNotHasKey('recurrenceRules', $jscalendar);
    }

    public function testATimedEventWithNoZoneOfItsOwnTakesTheCalendars(): void
    {
        // Google returns an explicit zone on recurring events and often omits
        // it on single ones. The instant is never in doubt; only which wall
        // clock to show it against, and the calendar's is the one Google's own
        // interface would use.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [[
                'id'     => 'ev-1',
                'status' => 'confirmed',
                'start'  => ['dateTime' => '2026-08-04T10:00:00+02:00'],
                'end'    => ['dateTime' => '2026-08-04T11:00:00+02:00'],
            ]],
        ]));

        $event = $fixture->driver->pull(GoogleDriverFixture::calendar(null, 'Europe/Berlin'), null)->events[0];

        self::assertSame('Europe/Berlin', $event->jscalendar['timeZone'] ?? null);
        self::assertSame('2026-08-04T10:00:00', $event->jscalendar['start'] ?? null);
    }

    public function testAnEventWithNoStartIsSkippedRatherThanFailingTheWindow(): void
    {
        // One malformed resource must not cost the other ninety-nine in the
        // page, and an event with no times would be written as a row the range
        // query can never return.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [
                ['id' => 'ev-broken', 'status' => 'confirmed', 'summary' => 'No times at all'],
                $this->timedEvent(),
            ],
        ]));

        $changes = $fixture->driver->pull(GoogleDriverFixture::calendar(), null);

        self::assertCount(1, $changes->events);
        self::assertSame('ev-1', $changes->events[0]->remoteId);
    }

    public function testAnUnendingEventIsGivenAnHourRatherThanNoLength(): void
    {
        // A zero-length row is invisible in every view, which presents as "the
        // event synced but is not on the calendar".
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [[
                'id'                 => 'ev-1',
                'status'             => 'confirmed',
                'start'              => ['dateTime' => '2026-08-04T10:00:00Z'],
                'end'                => ['dateTime' => '2026-08-04T10:00:00Z'],
                'endTimeUnspecified' => true,
            ]],
        ]));

        $event = $fixture->driver->pull(GoogleDriverFixture::calendar(), null)->events[0];

        self::assertSame('2026-08-04T11:00:00+00:00', $event->endsAt?->format('c'));
    }

    public function testTheCalendarIdIsEscapedIntoThePath(): void
    {
        $calendar           = GoogleDriverFixture::calendar();
        $calendar->remoteId = 'de.german#holiday@group.v.calendar.google.com';

        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json(['items' => []]));

        $fixture->driver->pull($calendar, null);

        self::assertStringContainsString(
            '/calendars/de.german%23holiday%40group.v.calendar.google.com/events',
            $fixture->url(0),
            'an unescaped # truncates the path and lists the wrong calendar',
        );
    }

    // ── Fixture ───────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function timedEvent(string $id = 'ev-1', string $uid = 'uid-1@google.com'): array
    {
        return [
            'id'          => $id,
            'etag'        => '"3181"',
            'iCalUID'     => $uid,
            'status'      => 'confirmed',
            'summary'     => 'Standup',
            'description' => 'Daily, briefly',
            'location'    => 'Room 3',
            'sequence'    => 2,
            'start'       => ['dateTime' => '2026-08-04T10:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
            'end'         => ['dateTime' => '2026-08-04T10:30:00+02:00', 'timeZone' => 'Europe/Berlin'],
            'organizer'   => ['email' => 'Alice@Example.com', 'displayName' => 'Alice'],
            'attendees'   => [
                ['email' => 'alice@example.com', 'displayName' => 'Alice', 'responseStatus' => 'accepted'],
                ['email' => 'bob@example.com', 'responseStatus' => 'needsAction', 'optional' => true],
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        /** @var array<string,string> $query */
        return $query;
    }
}
