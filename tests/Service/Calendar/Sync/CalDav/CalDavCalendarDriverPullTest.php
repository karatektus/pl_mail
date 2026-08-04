<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\CalDav;

use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\CalendarSyncThrottledException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * Reading changes off a server, both ways it can be done.
 *
 * The claim: **sync-collection is used when the server advertises it and the
 * ctag fallback when it does not, and the fallback never pretends to know about
 * deletions.** That second half is the subject. A calendar-query answers with
 * everything that currently exists and says nothing about what was removed, and
 * the engine only removes local rows a listing did not mention when it asked
 * with a null token — so returning the listing against a live token would apply
 * every edit and keep every deleted event forever. The driver surrenders the
 * token instead and lets the engine re-read from scratch, which is one extra
 * request per change in exchange for a calendar that does not accumulate
 * ghosts.
 *
 * The fallback is not an edge case. Radicale, older Baïkal and several
 * appliance servers advertise no sync-collection at all, and a driver that only
 * spoke RFC 6578 would report "cannot sync" for a working CalDAV server.
 *
 * Two more claims are about surviving a server's own limits: a truncated sync
 * report is followed to its end rather than silently dropping the rest of the
 * window, and a dead token is recognised whichever status it arrives under.
 */
final class CalDavCalendarDriverPullTest extends TestCase
{
    public function testASyncReportMapsACreateAnUpdateAndARemoval(): void
    {
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->collectionProbe(syncCollection: true)),
            CalDavFixture::multistatus($this->syncReport()),
        );

        $changes = $fixture->driver->pull(CalDavFixture::calendar(), 'sync-token-17');

        self::assertFalse($changes->requiresFullResync);
        self::assertCount(3, $changes->events);

        [$created, $updated, $removed] = $changes->events;

        self::assertSame('https://dav.example.com/calendars/alice/personal/standup.ics', $created->remoteId);
        self::assertSame('standup-42', $created->uid);
        self::assertSame('"etag-1"', $created->etag, 'the etag is stored exactly as the server wrote it, quotes and all');
        self::assertSame('Standup', $created->jscalendar['title'] ?? null);
        self::assertSame('2026-08-04T08:00:00', $created->startsAt?->format('Y-m-d\TH:i:s'));

        self::assertSame('Retro (moved)', $updated->jscalendar['title'] ?? null);

        // A 404 inside the multistatus is how RFC 6578 spells a deletion. Read
        // as anything else, a removed event stays on the calendar forever.
        self::assertTrue($removed->isDeleted);
        self::assertNull($removed->jscalendar);
        self::assertSame('https://dav.example.com/calendars/alice/personal/gone.ics', $removed->remoteId);

        self::assertSame('sync-token-18', $changes->nextSyncToken);
    }

    public function testTheStoredTokenIsPresentedAndTheReportIsARealReport(): void
    {
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->collectionProbe(syncCollection: true)),
            CalDavFixture::multistatus($this->syncReport()),
        );

        $fixture->driver->pull(CalDavFixture::calendar(), 'sync-token-17');

        self::assertSame('REPORT', $fixture->method(1));
        self::assertStringContainsString('<d:sync-token>sync-token-17</d:sync-token>', $fixture->body(1));
        self::assertStringContainsString('<cal:calendar-data/>', $fixture->body(1));
        self::assertSame('1', $fixture->header(1, 'Depth'));
    }

    public function testAFirstPullSendsAnEmptySyncTokenRatherThanNoReport(): void
    {
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->collectionProbe(syncCollection: true)),
            CalDavFixture::multistatus($this->syncReport()),
        );

        $fixture->driver->pull(CalDavFixture::calendar(), null);

        // An empty sync-token element is how RFC 6578 says "send me
        // everything"; omitting the element entirely is a different request.
        self::assertStringContainsString('<d:sync-token></d:sync-token>', $fixture->body(1));
    }

    public function testADeadSyncTokenAsksForAFullResyncRatherThanFailing(): void
    {
        // The precondition arrives as 403 here, 409 elsewhere and 400 on at
        // least one server, so the body is what identifies it. Classified from
        // the status alone, this reads as "your app password was rejected" and
        // sends the user to fix something that is not broken.
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->collectionProbe(syncCollection: true)),
            CalDavFixture::deadSyncToken(403),
        );

        $this->expectException(CalendarResyncRequiredException::class);

        $fixture->driver->pull(CalDavFixture::calendar(), 'ancient-token');
    }

    public function testAServerThatRefusesToReportAtAllAsksForAFullResync(): void
    {
        // HTTP 507 on the report itself: not "the disk is full", but "that is
        // more than I will produce". A full read is the way out.
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->collectionProbe(syncCollection: true)),
            CalDavFixture::status(507),
        );

        $this->expectException(CalendarResyncRequiredException::class);

        $fixture->driver->pull(CalDavFixture::calendar(), 'sync-token-17');
    }

    public function testATruncatedSyncReportIsFollowedToItsEnd(): void
    {
        // The in-body 507 on the collection's own href is the opposite of the
        // HTTP one: it means "here is a page, come back with this token".
        // Stopping at the first page loses the rest of the window silently.
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->collectionProbe(syncCollection: true)),
            CalDavFixture::multistatus($this->truncatedPage()),
            CalDavFixture::multistatus($this->syncReport()),
        );

        $changes = $fixture->driver->pull(CalDavFixture::calendar(), 'sync-token-17');

        self::assertCount(4, $changes->events, 'one from the truncated page, three from the last');
        self::assertStringContainsString('<d:sync-token>sync-token-partial</d:sync-token>', $fixture->body(2));
        self::assertSame('sync-token-18', $changes->nextSyncToken, 'the position after the last event, not the page cursor');
    }

    public function testEventsTheReportLeftEmptyAreFetchedInOneMultiget(): void
    {
        // sync-collection may legally answer with etags alone, and several
        // servers do for large windows. One GET each would be a round trip per
        // changed event.
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->collectionProbe(syncCollection: true)),
            CalDavFixture::multistatus($this->etagsOnlyReport()),
            CalDavFixture::multistatus($this->multigetResponse()),
        );

        $changes = $fixture->driver->pull(CalDavFixture::calendar(), 'sync-token-17');

        self::assertSame(3, $fixture->requestCount(), 'one multiget, not one GET per event');
        self::assertStringContainsString('calendar-multiget', $fixture->body(2));
        self::assertStringContainsString('/calendars/alice/personal/standup.ics', $fixture->body(2));
        self::assertCount(1, $changes->events);
        self::assertSame('Standup', $changes->events[0]->jscalendar['title'] ?? null);
    }

    public function testTheCtagFallbackReportsNothingWhenTheCtagHasNotMoved(): void
    {
        // The answer to most polls, and the whole point of the ctag: one
        // PROPFIND and no report at all.
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->collectionProbe(syncCollection: false, ctag: 'ctag-9')),
        );

        $changes = $fixture->driver->pull(CalDavFixture::calendar(), 'ctag-9');

        self::assertSame([], $changes->events);
        self::assertSame('ctag-9', $changes->nextSyncToken, 'the position is kept, not cleared');
        self::assertFalse($changes->requiresFullResync);
        self::assertSame(1, $fixture->requestCount());
    }

    public function testAMovedCtagSurrendersTheTokenSoDeletionsAreNotMissed(): void
    {
        // Answering with the listing here would apply every edit and keep every
        // deleted event forever, because the engine only prunes on a full read.
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->collectionProbe(syncCollection: false, ctag: 'ctag-10')),
        );

        $changes = $fixture->driver->pull(CalDavFixture::calendar(), 'ctag-9');

        self::assertTrue($changes->requiresFullResync);
        self::assertSame(1, $fixture->requestCount(), 'no listing is fetched for a window that cannot express deletions');
    }

    public function testTheFullReadOverTheCtagFallbackListsEveryEventAndKeepsThePosition(): void
    {
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->collectionProbe(syncCollection: false, ctag: 'ctag-10')),
            CalDavFixture::multistatus($this->queryResponse()),
        );

        $changes = $fixture->driver->pull(CalDavFixture::calendar(), null);

        self::assertCount(2, $changes->events);
        self::assertSame('ctag-10', $changes->nextSyncToken);
        self::assertStringContainsString('calendar-query', $fixture->body(1));

        // No time-range filter: the engine treats a full read as authoritative,
        // so a listing limited to the next six months would delete every event
        // outside the window.
        self::assertStringNotContainsString('time-range', $fixture->body(1));

        foreach ($changes->events as $event) {
            self::assertFalse($event->isDeleted, 'a full read carries no tombstones');
        }
    }

    public function testAServerWithNeitherMechanismStillReadsTheCalendar(): void
    {
        // No sync-collection and no ctag: every poll is a full read, which is
        // the only correct answer when the server offers no way to ask whether
        // anything changed.
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->collectionProbe(syncCollection: false, ctag: null)),
            CalDavFixture::multistatus($this->queryResponse()),
        );

        $changes = $fixture->driver->pull(CalDavFixture::calendar(), null);

        self::assertCount(2, $changes->events);
        self::assertNull($changes->nextSyncToken, 'nothing was learned to come back with');
    }

    public function testAStoredAddressInsideTheContainerNetworkIsRefusedBeforeAnyRequest(): void
    {
        // A pull never resolves the connection's base address — it goes
        // straight to the href stored on the calendar, which came from the
        // server rather than from the validated form. So the check has to be
        // on every request the client makes, not only on the one URL a person
        // typed. Without it, a server that answered discovery with
        // <href>http://127.0.0.1:5432/</href> gets its PROPFIND sent to
        // Postgres.
        $fixture  = new CalDavFixture();
        $calendar = CalDavFixture::calendar(remoteId: 'https://127.0.0.1:5432/calendars/alice/personal/');

        try {
            $fixture->driver->pull($calendar, null);
            self::fail('a private address must not be requested');
        } catch (CalendarSyncPermanentException $e) {
            self::assertStringContainsString('private network', $e->getMessage());
        }

        self::assertSame(0, $fixture->requestCount());
    }

    public function testARejectedAppPasswordIsPermanentAndSaysWhatToDo(): void
    {
        $fixture = new CalDavFixture(CalDavFixture::status(401));

        try {
            $fixture->driver->pull(CalDavFixture::calendar(), 'sync-token-17');
            self::fail('a 401 must not be swallowed');
        } catch (CalendarSyncPermanentException $e) {
            self::assertInstanceOf(UnrecoverableExceptionInterface::class, $e, 'Messenger must not retry this');
            self::assertSame(401, $e->getStatus());
            self::assertStringContainsString('app password', $e->getMessage());
            self::assertStringContainsString('Generate a new one', $e->getMessage());
        }
    }

    public function testAServerAskingUsToSlowDownIsRetriedWhenItSaidTo(): void
    {
        $fixture = new CalDavFixture(CalDavFixture::status(503, ['retry-after' => '120']));

        try {
            $fixture->driver->pull(CalDavFixture::calendar(), 'sync-token-17');
            self::fail('a 503 must not be swallowed');
        } catch (CalendarSyncThrottledException $e) {
            self::assertSame(120, $e->getRetryAfterSeconds());
            self::assertSame(120000, $e->getRetryDelay());
        }
    }

    public function testAnHttpDateRetryAfterFallsBackInsteadOfBeingMisparsed(): void
    {
        $fixture = new CalDavFixture(CalDavFixture::status(429, ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT']));

        try {
            $fixture->driver->pull(CalDavFixture::calendar(), 'sync-token-17');
            self::fail('a 429 must not be swallowed');
        } catch (CalendarSyncThrottledException $e) {
            self::assertNull($e->getRetryAfterSeconds());
            self::assertSame(60000, $e->getRetryDelay());
        }
    }

    public function testAResourceThatIsNotAnEventCostsThatResourceAndNothingElse(): void
    {
        // A CalDAV collection legitimately holds VTODOs and VJOURNALs, and a
        // sync report over one returns them. Throwing would cost the calendar.
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->collectionProbe(syncCollection: true)),
            CalDavFixture::multistatus($this->reportWithATask()),
        );

        $changes = $fixture->driver->pull(CalDavFixture::calendar(), 'sync-token-17');

        self::assertCount(1, $changes->events);
        self::assertSame('standup-42', $changes->events[0]->uid);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function collectionProbe(bool $syncCollection, ?string $ctag = 'ctag-9'): string
    {
        $reports = true === $syncCollection
            ? '<d:supported-report-set><d:supported-report><d:report><d:sync-collection/></d:report></d:supported-report>'
                . '<d:supported-report><d:report><cal:calendar-query/></d:report></d:supported-report></d:supported-report-set>'
            : '<d:supported-report-set><d:supported-report><d:report><cal:calendar-query/></d:report></d:supported-report>'
                . '<d:supported-report><d:report><cal:calendar-multiget/></d:report></d:supported-report></d:supported-report-set>';

        $ctagElement = null === $ctag ? '' : sprintf('<cs:getctag>%s</cs:getctag>', $ctag);

        return sprintf(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/">
              <d:response>
                <d:href>/calendars/alice/personal/</d:href>
                <d:propstat>
                  <d:prop>
                    <d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>
                    <d:displayname>Personal</d:displayname>
                    %s
                    %s
                  </d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
            </d:multistatus>
            XML, $reports, $ctagElement);
    }

    private function syncReport(): string
    {
        return sprintf(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
              <d:response>
                <d:href>/calendars/alice/personal/standup.ics</d:href>
                <d:propstat>
                  <d:prop><d:getetag>"etag-1"</d:getetag><cal:calendar-data>%s</cal:calendar-data></d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
              <d:response>
                <d:href>/calendars/alice/personal/retro.ics</d:href>
                <d:propstat>
                  <d:prop><d:getetag>"etag-2"</d:getetag><cal:calendar-data>%s</cal:calendar-data></d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
              <d:response>
                <d:href>/calendars/alice/personal/gone.ics</d:href>
                <d:status>HTTP/1.1 404 Not Found</d:status>
              </d:response>
              <d:sync-token>sync-token-18</d:sync-token>
            </d:multistatus>
            XML,
            CalDavFixture::calendarData(CalDavFixture::ics('standup-42', 'Standup')),
            CalDavFixture::calendarData(CalDavFixture::ics('retro-7', 'Retro (moved)')),
        );
    }

    private function truncatedPage(): string
    {
        return sprintf(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
              <d:response>
                <d:href>/calendars/alice/personal/first.ics</d:href>
                <d:propstat>
                  <d:prop><d:getetag>"etag-0"</d:getetag><cal:calendar-data>%s</cal:calendar-data></d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
              <d:response>
                <d:href>/calendars/alice/personal/</d:href>
                <d:status>HTTP/1.1 507 Insufficient Storage</d:status>
              </d:response>
              <d:sync-token>sync-token-partial</d:sync-token>
            </d:multistatus>
            XML, CalDavFixture::calendarData(CalDavFixture::ics('first-1', 'First')));
    }

    private function etagsOnlyReport(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:">
              <d:response>
                <d:href>/calendars/alice/personal/standup.ics</d:href>
                <d:propstat>
                  <d:prop><d:getetag>"etag-1"</d:getetag></d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
              <d:sync-token>sync-token-18</d:sync-token>
            </d:multistatus>
            XML;
    }

    private function multigetResponse(): string
    {
        return sprintf(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
              <d:response>
                <d:href>/calendars/alice/personal/standup.ics</d:href>
                <d:propstat>
                  <d:prop><d:getetag>"etag-1"</d:getetag><cal:calendar-data>%s</cal:calendar-data></d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
            </d:multistatus>
            XML, CalDavFixture::calendarData(CalDavFixture::ics('standup-42', 'Standup')));
    }

    private function queryResponse(): string
    {
        return sprintf(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
              <d:response>
                <d:href>/calendars/alice/personal/standup.ics</d:href>
                <d:propstat>
                  <d:prop><d:getetag>"etag-1"</d:getetag><cal:calendar-data>%s</cal:calendar-data></d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
              <d:response>
                <d:href>/calendars/alice/personal/retro.ics</d:href>
                <d:propstat>
                  <d:prop><d:getetag>"etag-2"</d:getetag><cal:calendar-data>%s</cal:calendar-data></d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
            </d:multistatus>
            XML,
            CalDavFixture::calendarData(CalDavFixture::ics('standup-42', 'Standup')),
            CalDavFixture::calendarData(CalDavFixture::ics('retro-7', 'Retro')),
        );
    }

    private function reportWithATask(): string
    {
        $task = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:task-1\r\nSUMMARY:Buy milk\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";

        return sprintf(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
              <d:response>
                <d:href>/calendars/alice/personal/task.ics</d:href>
                <d:propstat>
                  <d:prop><d:getetag>"etag-t"</d:getetag><cal:calendar-data>%s</cal:calendar-data></d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
              <d:response>
                <d:href>/calendars/alice/personal/standup.ics</d:href>
                <d:propstat>
                  <d:prop><d:getetag>"etag-1"</d:getetag><cal:calendar-data>%s</cal:calendar-data></d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
              <d:sync-token>sync-token-18</d:sync-token>
            </d:multistatus>
            XML,
            CalDavFixture::calendarData($task),
            CalDavFixture::calendarData(CalDavFixture::ics('standup-42', 'Standup')),
        );
    }
}
