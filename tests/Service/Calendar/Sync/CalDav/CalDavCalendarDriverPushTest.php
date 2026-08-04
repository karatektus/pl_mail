<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\CalDav;

use App\Domain\Exception\CalendarResyncRequiredException;
use PHPUnit\Framework\TestCase;

/**
 * Writing one event back, and the conditional headers that make it safe.
 *
 * The claim: **a write says what it expects to find, and a write that finds
 * something else stops.** Which header carries that is decided by
 * CalendarEvent::$remoteId and nothing else — no remote id means create, so
 * `If-None-Match: *`, which turns two clients creating the same meeting into a
 * refusal for the second rather than a silent overwrite of the first. An
 * existing id means update, so `If-Match` with the stored etag, which turns a
 * write over somebody else's newer edit into a 412. Both 412s are a resync:
 * the calendar has moved under us and reading it again is the only way to find
 * out how.
 *
 * The etag-after-PUT case is the one nobody expects. A server is not required
 * to answer a PUT with an ETag and a good many do not, so the driver asks for
 * it with a GET — without which every pushed event stores a null etag, is
 * treated as changed by the next pull, and is re-written on every sweep for as
 * long as it exists.
 *
 * Deleting is idempotent by contract, so the interesting assertion is the one
 * about an event that is already gone: 404 is success, because the caller's job
 * is to make the event not exist and it does not. Treating it as a failure
 * leaves the row in PendingDelete forever, re-sending the same DELETE on every
 * sweep.
 */
final class CalDavCalendarDriverPushTest extends TestCase
{
    public function testCreatingAnEventMintsAnHrefFromTheUidAndRefusesToOverwrite(): void
    {
        $fixture = new CalDavFixture(CalDavFixture::status(201, ['etag' => '"etag-new"']));

        $result = $fixture->driver->push(CalDavFixture::calendar(), CalDavFixture::event('standup-42'));

        self::assertSame('PUT', $fixture->method(0));
        self::assertSame(
            'https://dav.example.com/calendars/alice/personal/standup-42.ics',
            $fixture->url(0),
            'CalDAV names a resource after the UID it holds',
        );
        self::assertSame('*', $fixture->header(0, 'If-None-Match'));
        self::assertNull($fixture->header(0, 'If-Match'), 'there is no version to match on a create');
        self::assertStringContainsString('text/calendar', (string) $fixture->header(0, 'Content-Type'));

        self::assertSame('https://dav.example.com/calendars/alice/personal/standup-42.ics', $result->remoteId);
        self::assertSame('"etag-new"', $result->etag);
    }

    public function testAUidWithUrlCharactersInItIsEncodedRatherThanPastedIn(): void
    {
        // A UID is somebody else's string and routinely carries a slash or an
        // at-sign. Unencoded, one of those creates the event in a different
        // collection, or in none.
        $fixture = new CalDavFixture(CalDavFixture::status(201, ['etag' => '"e"']));

        $fixture->driver->push(CalDavFixture::calendar(), CalDavFixture::event('a/b@example.com'));

        self::assertSame(
            'https://dav.example.com/calendars/alice/personal/a%2Fb%40example.com.ics',
            $fixture->url(0),
        );
    }

    public function testUpdatingAnEventSendsTheStoredEtagAndNotTheCreateGuard(): void
    {
        $fixture = new CalDavFixture(CalDavFixture::status(204, ['etag' => '"etag-3"']));

        $event  = CalDavFixture::event(
            'standup-42',
            'https://dav.example.com/calendars/alice/personal/standup-42.ics',
            '"etag-2"',
        );
        $result = $fixture->driver->push(CalDavFixture::calendar(), $event);

        self::assertSame('"etag-2"', $fixture->header(0, 'If-Match'));
        self::assertNull($fixture->header(0, 'If-None-Match'), 'If-None-Match: * on an update would refuse every update');
        self::assertSame('https://dav.example.com/calendars/alice/personal/standup-42.ics', $result->remoteId);
        self::assertSame('"etag-3"', $result->etag);
        self::assertSame(1, $fixture->requestCount(), 'the server said the new etag, so nothing was asked twice');
    }

    public function testAPutThatReturnsNoEtagIsFollowedByAGetToLearnIt(): void
    {
        $fixture = new CalDavFixture(
            CalDavFixture::status(204),
            CalDavFixture::status(200, ['etag' => '"etag-4"'], 'BEGIN:VCALENDAR'),
        );

        $result = $fixture->driver->push(
            CalDavFixture::calendar(),
            CalDavFixture::event('standup-42', 'https://dav.example.com/calendars/alice/personal/standup-42.ics', '"etag-2"'),
        );

        self::assertSame('GET', $fixture->method(1));
        self::assertSame('https://dav.example.com/calendars/alice/personal/standup-42.ics', $fixture->url(1));
        self::assertSame('"etag-4"', $result->etag);
    }

    public function testAnEtagThatCannotBeReadBackIsNullRatherThanAFailedPush(): void
    {
        // The write has already succeeded. Null makes the next pull treat the
        // event as changed and repair the etag, which is a wasted write —
        // failing here would instead re-push an event the server already has.
        $fixture = new CalDavFixture(
            CalDavFixture::status(204),
            CalDavFixture::status(500),
        );

        $result = $fixture->driver->push(
            CalDavFixture::calendar(),
            CalDavFixture::event('standup-42', 'https://dav.example.com/calendars/alice/personal/standup-42.ics'),
        );

        self::assertNull($result->etag);
        self::assertSame('https://dav.example.com/calendars/alice/personal/standup-42.ics', $result->remoteId);
    }

    public function testAPreconditionFailureOnAWriteAsksForAFullResync(): void
    {
        $fixture = new CalDavFixture(CalDavFixture::status(412));

        $this->expectException(CalendarResyncRequiredException::class);

        $fixture->driver->push(
            CalDavFixture::calendar(),
            CalDavFixture::event('standup-42', 'https://dav.example.com/calendars/alice/personal/standup-42.ics', '"stale"'),
        );
    }

    public function testTheBodyIsAWholeCalendarObjectRatherThanABareEvent(): void
    {
        $fixture = new CalDavFixture(CalDavFixture::status(201, ['etag' => '"e"']));

        $fixture->driver->push(CalDavFixture::calendar(), CalDavFixture::event('standup-42'));

        $body = $fixture->body(0);

        self::assertStringContainsString('BEGIN:VCALENDAR', $body);
        self::assertStringContainsString('UID:standup-42', $body);
        self::assertStringContainsString('SUMMARY:Standup', $body);
        // METHOD belongs to iTIP. A resource stored with METHOD:REQUEST on it
        // is an invitation some clients will try to deliver.
        self::assertStringNotContainsString('METHOD:', $body);
    }

    public function testDeletingSendsTheStoredEtagSoANewerCopyIsNotLost(): void
    {
        $fixture = new CalDavFixture(CalDavFixture::status(204));

        $fixture->driver->delete(
            CalDavFixture::calendar(),
            CalDavFixture::event('standup-42', 'https://dav.example.com/calendars/alice/personal/standup-42.ics', '"etag-2"'),
        );

        self::assertSame('DELETE', $fixture->method(0));
        self::assertSame('"etag-2"', $fixture->header(0, 'If-Match'));
    }

    public function testDeletingSomethingAlreadyGoneIsSuccess(): void
    {
        // Idempotent by contract: the engine retries jobs, and treating the
        // second delete as a failure leaves the row in PendingDelete forever.
        $fixture = new CalDavFixture(CalDavFixture::status(404));

        $fixture->driver->delete(
            CalDavFixture::calendar(),
            CalDavFixture::event('standup-42', 'https://dav.example.com/calendars/alice/personal/standup-42.ics'),
        );

        $this->expectNotToPerformAssertions();
    }

    public function testDeletingSomethingThatChangedUnderUsAsksForAFullResync(): void
    {
        $fixture = new CalDavFixture(CalDavFixture::status(412));

        $this->expectException(CalendarResyncRequiredException::class);

        $fixture->driver->delete(
            CalDavFixture::calendar(),
            CalDavFixture::event('standup-42', 'https://dav.example.com/calendars/alice/personal/standup-42.ics', '"stale"'),
        );
    }

    public function testDeletingAnEventTheRemoteNeverSawAsksTheServerNothing(): void
    {
        // A DELETE with no href would address the collection, which is the
        // calendar itself.
        $fixture = new CalDavFixture();

        $fixture->driver->delete(CalDavFixture::calendar(), CalDavFixture::event('standup-42'));

        self::assertSame(0, $fixture->requestCount());
    }
}
