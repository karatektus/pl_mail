<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\Graph;

use App\Domain\Enum\Calendar\EventPrivacy;
use App\Entity\Calendar\CalendarEvent;
use App\Service\Calendar\Alert\AlertReader;
use App\Service\Calendar\Sync\Graph\GraphEventMapper;
use App\Service\Calendar\Sync\Graph\GraphRecurrenceMapper;
use App\Service\Calendar\Sync\Graph\GraphTimeZoneMapper;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A Graph `event` is not a JSCalendar Event, and the difference is where the
 * data goes missing.
 *
 * The claims worth pinning are the ones whose failure is silent. An organiser
 * who is also going loses the `owner` role and the event ends up with nobody to
 * answer, which presents as an invite card with no buttons. A timed event that
 * keeps Graph's UTC as its zone rather than the organiser's own recurs at the
 * wrong hour for half the year, and only for recurring events, and only after
 * the March change. An all-day event that keeps a zone stops being all-day.
 * None of those look like a sync fault when they happen.
 *
 * Built for real rather than doubled — every collaborator is final — and against
 * fixtures shaped like Graph's own responses, because the shapes are the part
 * that has to be got right.
 */
final class GraphEventMapperTest extends TestCase
{
    public function testATimedEventKeepsTheOrganisersZoneRatherThanTheUtcGraphAnswersIn(): void
    {
        // Graph returns every dateTimeTimeZone in UTC and keeps the zone the
        // organiser chose on originalStartTimeZone. Reading the zone off `start`
        // stores UTC, and a weekly 11:00 Berlin standup then expands at 11:00
        // UTC — an hour out from late March to late October, every year.
        $remote = $this->mapper()->toRemoteEvent($this->graphEvent());

        self::assertNotNull($remote);
        self::assertSame('Europe/Berlin', $remote->jscalendar['timeZone'] ?? null);
        self::assertSame('2026-08-04T11:00:00', $remote->jscalendar['start'] ?? null);
        self::assertSame('PT1H', $remote->jscalendar['duration'] ?? null);
        self::assertSame('2026-08-04T09:00:00+00:00', $remote->startsAt?->format('c'));
        self::assertSame('2026-08-04T10:00:00+00:00', $remote->endsAt?->format('c'));
    }

    public function testAnEventIsIdentifiedByItsICalUidAndNotByItsGraphId(): void
    {
        // The UID is what an invitation sitting in the mailbox and the same
        // meeting on the calendar have in common; without it CalendarPuller's
        // uid fallback never fires and accepting an invite writes the meeting
        // twice.
        $remote = $this->mapper()->toRemoteEvent($this->graphEvent());

        self::assertNotNull($remote);
        self::assertSame('AAMkAGI2==', $remote->remoteId);
        self::assertSame('040000008200E00074C5B7101A82E008', $remote->uid);
        self::assertSame('W/"CQAAABYAAAA"', $remote->etag);
    }

    public function testAnAllDayEventFloatsInsteadOfCarryingAZone(): void
    {
        // CalendarEvent stores all-day events as local midnight with a null
        // timeZone. A zone here makes CalendarEventWriter project a timed event
        // that renders in a strip rather than across the day.
        $remote = $this->mapper()->toRemoteEvent([
            'id'                    => 'ALLDAY',
            'iCalUId'               => 'uid-allday',
            'subject'               => 'Company holiday',
            'isAllDay'              => true,
            'start'                 => ['dateTime' => '2026-08-04T00:00:00.0000000', 'timeZone' => 'UTC'],
            'end'                   => ['dateTime' => '2026-08-05T00:00:00.0000000', 'timeZone' => 'UTC'],
            'originalStartTimeZone' => 'W. Europe Standard Time',
        ]);

        self::assertNotNull($remote);
        self::assertTrue($remote->jscalendar['showWithoutTime'] ?? null);
        self::assertArrayNotHasKey('timeZone', $remote->jscalendar);
        self::assertSame('2026-08-04T00:00:00', $remote->jscalendar['start'] ?? null);
        self::assertSame('P1D', $remote->jscalendar['duration'] ?? null);
    }

    public function testAnOrganiserWhoIsAlsoGoingKeepsBothRoles(): void
    {
        // Graph lists the organiser in `organizer` and again in `attendees`
        // whenever they are attending, which is every meeting anybody schedules
        // for themselves. Keyed by address and written in property order, the
        // attendee line replaces the organiser line — the only participant
        // carrying `owner` loses it, the event has no organiser, and the invite
        // card has nobody to answer and so offers no answer. The same defect
        // IcsEventExtractor::participantsOf() was fixed for.
        $remote = $this->mapper()->toRemoteEvent($this->graphEvent());

        self::assertNotNull($remote);

        $participants = $remote->jscalendar['participants'] ?? [];

        self::assertSame(
            ['owner' => true, 'attendee' => true],
            $participants['alice@example.com']['roles'] ?? null,
            'the organiser must keep owner when they also appear as an attendee',
        );
        self::assertSame('accepted', $participants['alice@example.com']['participationStatus'] ?? null);
        self::assertSame('Alice Adams', $participants['alice@example.com']['name'] ?? null);
    }

    public function testAnAttendeeWhoHasNotRepliedIsUnansweredAndAnOptionalOneSaysSo(): void
    {
        $participants = $this->mapper()->toRemoteEvent($this->graphEvent())?->jscalendar['participants'] ?? [];

        self::assertSame(['attendee' => true], $participants['bob@example.com']['roles'] ?? null);
        self::assertSame('needs-action', $participants['bob@example.com']['participationStatus'] ?? null);
        self::assertSame(
            ['attendee' => true, 'optional' => true],
            $participants['carol@example.com']['roles'] ?? null,
        );
        self::assertSame('tentative', $participants['carol@example.com']['participationStatus'] ?? null);
    }

    public function testAnOutOfOfficeBlockIsBusyWithoutForgettingItWasOutOfOffice(): void
    {
        // JSCalendar has free and busy and nothing else. Losing `oof` here means
        // a push straight afterwards quietly demotes somebody's leave to an
        // ordinary meeting in their colleagues' free/busy view.
        $remote = $this->mapper()->toRemoteEvent(['id' => 'X', 'showAs' => 'oof'] + $this->times());

        self::assertNotNull($remote);
        self::assertSame('busy', $remote->jscalendar['freeBusyStatus'] ?? null);
        self::assertSame('oof', $remote->jscalendar['plmail:graphShowAs'] ?? null);
    }

    public function testACancelledMeetingStaysOnTheCalendarAsCancelled(): void
    {
        $remote = $this->mapper()->toRemoteEvent(['id' => 'X', 'isCancelled' => true] + $this->times());

        self::assertSame('cancelled', $remote?->jscalendar['status'] ?? null);
    }

    public function testAPrivateOutlookEventIsPrivateHereToo(): void
    {
        $remote = $this->mapper()->toRemoteEvent(['id' => 'X', 'sensitivity' => 'confidential'] + $this->times());

        self::assertSame(EventPrivacy::Secret->value, $remote?->jscalendar['privacy'] ?? null);
    }

    public function testAnHtmlBodySaysThatItIsHtml(): void
    {
        // A description stored as HTML and read as text renders the tags; read
        // the other way it strips the formatting the organiser wrote.
        $remote = $this->mapper()->toRemoteEvent([
            'id'   => 'X',
            'body' => ['contentType' => 'HTML', 'content' => '<p>Bring the deck</p>'],
        ] + $this->times());

        self::assertSame('<p>Bring the deck</p>', $remote?->jscalendar['description'] ?? null);
        self::assertSame('text/html', $remote?->jscalendar['descriptionContentType'] ?? null);
    }

    public function testAnEventWithNoStartIsSkippedRatherThanWrittenAsARowNoQueryCanFind(): void
    {
        // CalendarPuller refuses an incomplete event; returning one anyway costs
        // a warning per sweep for an event nobody can see. Returning null here
        // is the driver's own answer and does not fail the window.
        self::assertNull($this->mapper()->toRemoteEvent(['id' => 'X']));
        self::assertNull($this->mapper()->toRemoteEvent($this->times()));
    }

    // ── Writing Graph ────────────────────────────────────────────────────────

    public function testALocalEventGoesOutInItsOwnZoneUnderTheNameOutlookUses(): void
    {
        $body = $this->mapper()->toGraphEvent($this->localEvent());

        self::assertSame(
            ['dateTime' => '2026-08-04T11:00:00', 'timeZone' => 'W. Europe Standard Time'],
            $body['start'] ?? null,
        );
        self::assertSame(
            ['dateTime' => '2026-08-04T12:00:00', 'timeZone' => 'W. Europe Standard Time'],
            $body['end'] ?? null,
        );
        self::assertSame('Standup', $body['subject'] ?? null);
        self::assertSame('Room 3', $body['location']['displayName'] ?? null);
    }

    public function testClearingSomethingLocallyClearsItAtTheRemoteToo(): void
    {
        // A PATCH leaves out what it does not mention, so an omitted description
        // is indistinguishable from one that was never set — and notes a user
        // deleted would come back on the next pull.
        $event             = $this->localEvent();
        $event->location   = null;
        $event->jscalendar = ['@type' => 'Event'];

        $body = $this->mapper()->toGraphEvent($event);

        self::assertSame('', $body['location']['displayName'] ?? null);
        self::assertSame('', $body['body']['content'] ?? null);
        self::assertArrayHasKey('recurrence', $body, 'a removed rule must remove the series, not be omitted');
        self::assertNull($body['recurrence']);
    }

    public function testTheOrganiserIsNotSentBackAsAnAttendeeOfTheirOwnMeeting(): void
    {
        // Graph makes the mailbox owner the organizer of anything created in
        // their calendar and refuses any other, so the owner role cannot be
        // asserted — but sending the owner as an attendee would show them
        // invited to their own meeting in Outlook.
        $event = $this->localEvent();

        $event->jscalendar['participants'] = [
            'alice@example.com' => [
                '@type' => 'Participant',
                'email' => 'alice@example.com',
                'roles' => ['owner' => true],
            ],
            'bob@example.com' => [
                '@type'               => 'Participant',
                'email'               => 'bob@example.com',
                'name'                => 'Bob Brown',
                'roles'               => ['attendee' => true, 'optional' => true],
                'participationStatus' => 'declined',
            ],
        ];

        $attendees = $this->mapper()->toGraphEvent($event)['attendees'] ?? [];

        self::assertCount(1, $attendees);
        self::assertSame('bob@example.com', $attendees[0]['emailAddress']['address'] ?? null);
        self::assertSame('optional', $attendees[0]['type'] ?? null);
        self::assertSame('declined', $attendees[0]['status']['response'] ?? null);
    }

    public function testAnAllDayEventGoesOutAsMidnightUtcRatherThanInTheUsersZone(): void
    {
        // Graph stores an all-day event as midnight-to-midnight and rejects one
        // whose bounds are not midnight in the zone given. The local row is
        // already local midnight held as UTC, so UTC is the only zone that
        // keeps it on the right day.
        $event           = $this->localEvent();
        $event->isAllDay = true;

        $body = $this->mapper()->toGraphEvent($event);

        self::assertTrue($body['isAllDay'] ?? null);
        self::assertSame('UTC', $body['start']['timeZone'] ?? null);
        self::assertSame('2026-08-04T09:00:00', $body['start']['dateTime'] ?? null);
    }

    public function testAPreservedOutOfOfficeGoesBackOutAsOutOfOffice(): void
    {
        $event = $this->localEvent();

        $event->jscalendar['freeBusyStatus']     = 'busy';
        $event->jscalendar['plmail:graphShowAs'] = 'oof';

        self::assertSame('oof', $this->mapper()->toGraphEvent($event)['showAs'] ?? null);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function mapper(): GraphEventMapper
    {
        return new GraphEventMapper(new GraphTimeZoneMapper(), new GraphRecurrenceMapper(), new AlertReader(new NullLogger()));
    }

    /**
     * A Graph event resource in the shape /me/calendars/{id}/calendarView/delta
     * actually answers with.
     *
     * @return array<string,mixed>
     */
    private function graphEvent(): array
    {
        return [
            'id'                    => 'AAMkAGI2==',
            '@odata.etag'           => 'W/"CQAAABYAAAA"',
            'iCalUId'               => '040000008200E00074C5B7101A82E008',
            'subject'               => 'Standup',
            'isAllDay'              => false,
            'isCancelled'           => false,
            'showAs'                => 'busy',
            'sensitivity'           => 'normal',
            'originalStartTimeZone' => 'W. Europe Standard Time',
            'organizer'             => [
                'emailAddress' => ['name' => 'Alice Adams', 'address' => 'alice@example.com'],
            ],
            'attendees' => [
                [
                    'type'         => 'required',
                    'status'       => ['response' => 'organizer'],
                    'emailAddress' => ['name' => 'Alice Adams', 'address' => 'alice@example.com'],
                ],
                [
                    'type'         => 'required',
                    'status'       => ['response' => 'notResponded'],
                    'emailAddress' => ['name' => 'bob@example.com', 'address' => 'bob@example.com'],
                ],
                [
                    'type'         => 'optional',
                    'status'       => ['response' => 'tentativelyAccepted'],
                    'emailAddress' => ['name' => 'Carol Clark', 'address' => 'carol@example.com'],
                ],
            ],
        ] + $this->times();
    }

    /**
     * @return array<string,mixed>
     */
    private function times(): array
    {
        return [
            'start' => ['dateTime' => '2026-08-04T09:00:00.0000000', 'timeZone' => 'UTC'],
            'end'   => ['dateTime' => '2026-08-04T10:00:00.0000000', 'timeZone' => 'UTC'],
        ];
    }

    private function localEvent(): CalendarEvent
    {
        $event = new CalendarEvent();

        $event->uid        = 'local-uid';
        $event->title      = 'Standup';
        $event->location   = 'Room 3';
        $event->timeZone   = 'Europe/Berlin';
        $event->startsAt   = new DateTimeImmutable('2026-08-04 09:00:00', new DateTimeZone('UTC'));
        $event->endsAt     = new DateTimeImmutable('2026-08-04 10:00:00', new DateTimeZone('UTC'));
        $event->jscalendar = ['@type' => 'Event', 'description' => 'Bring the deck'];

        return $event;
    }
}
