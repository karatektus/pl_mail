<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\Google;

use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Service\Calendar\Sync\Google\GoogleRecurrenceMapper;
use PHPUnit\Framework\TestCase;

/**
 * A local change on its way to Google, and the request it actually becomes.
 *
 * Everything asserted here is a property of the request rather than of the
 * answer, because that is where this half goes wrong. Four claims:
 *
 *   **A create is a POST and an update is a PATCH against the event.** Which of
 *   the two is read off CalendarEvent::$remoteId, because the id is the fact —
 *   a flag beside it would be a second copy of that fact that can disagree.
 *
 *   **An update carries If-Match.** Without the header, a change somebody made
 *   in Google's own interface between the last pull and this push is
 *   overwritten silently, and the person who made it never learns it is gone.
 *   With it Google answers 412 and the engine re-reads instead — the whole
 *   point of storing an etag at all.
 *
 *   **PATCH, not PUT.** Google events carry things plMail does not model:
 *   reminders, a Meet link, guest permissions, a colour. A PUT sends the whole
 *   resource, so every one of those absent from the payload is cleared, and a
 *   user who fixed a typo in a title would find the video call gone from the
 *   meeting.
 *
 *   **What is modelled is sent even when it is empty; what is not modelled is
 *   not mentioned.** An emptied description has to travel as an explicit null
 *   or clearing it is a change that silently does not happen. A guest list
 *   plMail has nothing to say about must not travel at all, or fixing a typo
 *   uninvites everyone.
 */
final class GoogleCalendarPushTest extends TestCase
{
    public function testACreateIsAPostWithNoVersionToMatch(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'id'   => 'ev-new',
            'etag' => '"7"',
        ]));

        $result = $fixture->driver->push(GoogleDriverFixture::calendar(), GoogleDriverFixture::event());

        self::assertSame('POST', $fixture->method(0));
        self::assertStringContainsString('/calendars/primary/events', $fixture->url(0));
        self::assertNull($fixture->header(0, 'If-Match'), 'there is no version of an event that does not exist yet');

        self::assertSame('ev-new', $result->remoteId);
        self::assertSame('"7"', $result->etag);
    }

    public function testAnUpdateIsAPatchAgainstTheEventAndSaysWhichVersionItEdited(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'id'   => 'ev-1',
            'etag' => '"9"',
        ]));

        $event = GoogleDriverFixture::event(remoteId: 'ev-1', etag: '"8"');

        $result = $fixture->driver->push(GoogleDriverFixture::calendar(), $event);

        self::assertSame('PATCH', $fixture->method(0), 'a PUT would erase everything plMail does not model');
        self::assertStringContainsString('/calendars/primary/events/ev-1', $fixture->url(0));
        self::assertSame('"8"', $fixture->header(0, 'If-Match'), 'without this a concurrent edit is overwritten silently');

        self::assertSame('ev-1', $result->remoteId);
        self::assertSame('"9"', $result->etag, 'the new version is stored, or the next pull re-applies this write');
    }

    public function testAnUpdateWhoseEtagWasLostStillGoesOut(): void
    {
        // A null etag is not a version to match against, and refusing to send
        // the write would leave the row pending forever. The cost is the
        // overwrite the header exists to prevent, which is why the etag is
        // stored on every read and every write.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json(['id' => 'ev-1']));

        $fixture->driver->push(GoogleDriverFixture::calendar(), GoogleDriverFixture::event(remoteId: 'ev-1'));

        self::assertSame('PATCH', $fixture->method(0));
        self::assertNull($fixture->header(0, 'If-Match'));
    }

    public function testATimedEventCarriesBothItsOffsetAndItsZoneName(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json(['id' => 'ev-1']));

        $fixture->driver->push(GoogleDriverFixture::calendar(), GoogleDriverFixture::event());

        $payload = $fixture->payload(0);

        // The offset alone fixes the instant; the zone name is what makes a
        // recurring event move with its city rather than stay on an offset that
        // stops being right in October.
        self::assertSame(
            ['dateTime' => '2026-08-04T10:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
            $payload['start'],
        );
        self::assertSame(
            ['dateTime' => '2026-08-04T10:30:00+02:00', 'timeZone' => 'Europe/Berlin'],
            $payload['end'],
        );
        self::assertSame('Standup', $payload['summary']);
        self::assertSame('Room 3', $payload['location']);
        self::assertSame('confirmed', $payload['status']);
    }

    public function testAnAllDayEventIsPushedAsDatesRatherThanAsMidnights(): void
    {
        // Sent as midnight-to-midnight in a zone, an all-day event arrives at
        // Google as a timed one and shows up on two days for anybody east of
        // it.
        $event           = GoogleDriverFixture::event(isAllDay: true);
        $event->startsAt = new \DateTimeImmutable('2026-12-24 00:00:00', new \DateTimeZone('UTC'));
        $event->endsAt   = new \DateTimeImmutable('2026-12-27 00:00:00', new \DateTimeZone('UTC'));

        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json(['id' => 'ev-1']));

        $fixture->driver->push(GoogleDriverFixture::calendar(), $event);

        $payload = $fixture->payload(0);

        self::assertSame(['date' => '2026-12-24'], $payload['start']);
        self::assertSame(['date' => '2026-12-27'], $payload['end'], 'the end stays exclusive, as it is on both sides');
    }

    public function testAnEmptiedFieldTravelsAsANullSoThePatchClearsIt(): void
    {
        // A PATCH leaves out what it does not mention. Omitting an emptied
        // description makes "clear the description" a change that silently does
        // not happen, and the field reappears on the next pull.
        $event              = GoogleDriverFixture::event();
        $event->location    = null;
        $event->jscalendar  = ['@type' => 'Event'];

        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json(['id' => 'ev-1']));

        $fixture->driver->push(GoogleDriverFixture::calendar(), $event);

        $payload = $fixture->payload(0);

        self::assertArrayHasKey('description', $payload);
        self::assertNull($payload['description']);
        self::assertNull($payload['location']);
        self::assertArrayHasKey('recurrence', $payload);
        self::assertNull($payload['recurrence'], 'a series demoted to a single event has to say so');
    }

    public function testAGuestListPlmailHasNothingToSayAboutIsNotMentioned(): void
    {
        // Sending an empty attendees array would uninvite everybody the moment
        // somebody fixed a typo in the title — the local editor cannot express
        // a guest list, so "none here" means "nothing to say", not "remove
        // them".
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json(['id' => 'ev-1']));

        $fixture->driver->push(GoogleDriverFixture::calendar(), GoogleDriverFixture::event());

        self::assertArrayNotHasKey('attendees', $fixture->payload(0));
    }

    public function testTheGuestsGoBackWithTheirAnswersAndTheOrganiserDoesNot(): void
    {
        // Google does not accept an organiser through `attendees`; sending one
        // invites the person whose meeting it is to their own meeting, and
        // mails them about it.
        $event = GoogleDriverFixture::event(jscalendar: [
            '@type'        => 'Event',
            'participants' => [
                'carol@example.com' => [
                    '@type' => 'Participant',
                    'email' => 'carol@example.com',
                    'name'  => 'Carol',
                    'roles' => ['owner' => true],
                ],
                'alice@example.com' => [
                    '@type'               => 'Participant',
                    'email'               => 'alice@example.com',
                    'roles'               => ['owner' => true, 'attendee' => true],
                    'participationStatus' => 'accepted',
                ],
                'bob@example.com' => [
                    '@type'               => 'Participant',
                    'email'               => 'bob@example.com',
                    'roles'               => ['attendee' => true, 'optional' => true],
                    'participationStatus' => 'needs-action',
                ],
            ],
        ]);

        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json(['id' => 'ev-1']));

        $fixture->driver->push(GoogleDriverFixture::calendar(), $event);

        self::assertSame([
            ['email' => 'alice@example.com', 'responseStatus' => 'accepted'],
            ['email' => 'bob@example.com', 'optional' => true, 'responseStatus' => 'needsAction'],
        ], $fixture->payload(0)['attendees'] ?? null);
    }

    public function testARecurringEventKeepsTheExclusionsGoogleAlreadyHad(): void
    {
        // `recurrence` is replaced wholesale by a write, so a push that sent
        // only the RRULE would resurrect every instance the user had cancelled
        // in Google's own interface — silently, and on an edit as small as a
        // renamed meeting.
        $event = GoogleDriverFixture::event(remoteId: 'ev-1', jscalendar: [
            '@type'           => 'Event',
            'recurrenceRules' => [['@type' => 'RecurrenceRule', 'frequency' => 'weekly', 'interval' => 2]],
            GoogleRecurrenceMapper::PRESERVED_LINES => ['EXDATE;TZID=Europe/Berlin:20260817T100000'],
        ]);

        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json(['id' => 'ev-1']));

        $fixture->driver->push(GoogleDriverFixture::calendar(), $event);

        self::assertSame([
            'RRULE:FREQ=WEEKLY;INTERVAL=2',
            'EXDATE;TZID=Europe/Berlin:20260817T100000',
        ], $fixture->payload(0)['recurrence'] ?? null);
    }

    public function testAWriteGoogleAnswersWithNoIdIsAFailureRatherThanASecondCopy(): void
    {
        // Accepting it would leave the event created at Google, the local row
        // still looking unsynced, and the next push making a second copy of the
        // same meeting.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json(['etag' => '"7"']));

        $this->expectException(CalendarSyncException::class);

        $fixture->driver->push(GoogleDriverFixture::calendar(), GoogleDriverFixture::event());
    }

    public function testAnEventWithNoTimesIsRefusedInsteadOfBeingRetriedForever(): void
    {
        // Permanent, so CalendarPusher retires this one event and the other
        // nineteen still go out. No number of retries will grow a row an end.
        $event         = GoogleDriverFixture::event();
        $event->endsAt = null;

        $fixture = new GoogleDriverFixture();

        $this->expectException(CalendarSyncPermanentException::class);

        $fixture->driver->push(GoogleDriverFixture::calendar(), $event);
    }

    public function testDeletingSendsADeleteForTheEventAndNothingElse(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::empty());

        $fixture->driver->delete(
            GoogleDriverFixture::calendar(),
            GoogleDriverFixture::event(remoteId: 'ev-1', etag: '"8"'),
        );

        self::assertSame('DELETE', $fixture->method(0));
        self::assertStringContainsString('/calendars/primary/events/ev-1', $fixture->url(0));

        // No If-Match, deliberately: the contract says a delete is idempotent,
        // and a version check would turn "somebody edited it first" into a
        // failure of a deletion that is going to happen anyway — leaving the
        // row stuck in PendingDelete, re-attempting it on every sweep.
        self::assertNull($fixture->header(0, 'If-Match'));
    }

    public function testAnEventTheRemoteNeverSawIsNotDeletedThroughGoogle(): void
    {
        // No request at all: MockHttpClient has nothing queued, so a request
        // here would fail the test rather than pass it quietly.
        $fixture = new GoogleDriverFixture();

        $fixture->driver->delete(GoogleDriverFixture::calendar(), GoogleDriverFixture::event());

        $this->expectNotToPerformAssertions();
    }
}
