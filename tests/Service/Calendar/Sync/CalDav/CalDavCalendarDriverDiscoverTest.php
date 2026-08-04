<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\CalDav;

use App\Domain\DTO\Calendar\CalendarSource;
use PHPUnit\Framework\TestCase;

/**
 * What the subscribe screen is offered, and how much of it is guessed.
 *
 * The claim: **every fact about a collection is read from a property, and where
 * the property is missing the answer is the safe one rather than the tidy one.**
 * Three of these decide something a user cannot undo from the UI. A colour read
 * wrongly makes a calendar invisible against the background; a task list
 * offered as a calendar syncs nothing forever and looks broken; and read-only
 * guessed the wrong way either strands every edit silently, or offers editing
 * on a colleague's calendar and fails at the first push.
 *
 * The colour case is the one with a real bug behind it. Apple's calendar-color
 * is `#rrggbbaa` — eight digits, the last two an alpha channel — and
 * Calendar::$color is a seven-character column, so passing the value through
 * unexamined is a write that fails or a colour nothing can render.
 */
final class CalDavCalendarDriverDiscoverTest extends TestCase
{
    public function testTheCalendarHomeIsListedAsCalendarsWithAbsoluteIds(): void
    {
        $fixture = $this->fixture($this->home());

        $calendars = $fixture->driver->discover(CalendarSource::ofIntegration(CalDavFixture::integration()));

        self::assertCount(2, $calendars, 'the home itself is not a calendar');
        self::assertSame(['Personal', 'Team'], array_map(static fn ($c) => $c->name, $calendars));

        // Absolute, so an id keeps meaning the same resource after the
        // connection's address is edited — and after a bootstrap that landed on
        // another host, which is what RFC 6764 does.
        self::assertSame('https://dav.example.com/calendars/alice/personal/', $calendars[0]->remoteId);
        self::assertSame('1', $fixture->header(1, 'Depth'), 'a home is listed with Depth 1');
    }

    public function testAnAlphaChannelIsDroppedFromTheCalendarColour(): void
    {
        $fixture = $this->fixture($this->home());

        $calendars = $fixture->driver->discover(CalendarSource::ofIntegration(CalDavFixture::integration()));

        // #FF5733FF at the server; the column holds seven characters.
        self::assertSame('#ff5733', $calendars[0]->color);
    }

    public function testAColourNobodyCanRenderIsNoColourRatherThanAMangledOne(): void
    {
        $fixture = $this->fixture($this->home());

        $calendars = $fixture->driver->discover(CalendarSource::ofIntegration(CalDavFixture::integration()));

        // "seagreen" is what one server actually sends. Null lets the
        // provisioner pick from the palette, which is a colour a user can live
        // with; a truncated "#seagre" is not.
        self::assertNull($calendars[1]->color);
    }

    public function testACollectionWeCannotWriteToIsReadOnly(): void
    {
        $fixture = $this->fixture($this->home());

        $calendars = $fixture->driver->discover(CalendarSource::ofIntegration(CalDavFixture::integration()));

        self::assertFalse($calendars[0]->isReadOnly, 'write-content was granted');
        self::assertTrue($calendars[1]->isReadOnly, 'read and read-acl only');
    }

    public function testACollectionThatEnumeratesNoPrivilegesIsTreatedAsWritable(): void
    {
        // current-user-privilege-set is a SHOULD and several servers omit it.
        // Defaulting to read-only there would make every calendar on such a
        // server refuse edits with nothing on screen to explain why; defaulting
        // to writable costs at worst a 403 on the first push, reported as
        // itself.
        $fixture = $this->fixture($this->collection('<d:displayname>Bare</d:displayname>'));

        $calendars = $fixture->driver->discover(CalendarSource::ofIntegration(CalDavFixture::integration()));

        self::assertFalse($calendars[0]->isReadOnly);
    }

    public function testTheCoarseWritePrivilegeCountsAsWritable(): void
    {
        // A server granting <d:write/> without enumerating write-content is not
        // read-only, and treating it as such strands every edit a user makes.
        $fixture = $this->fixture($this->collection(
            '<d:displayname>Coarse</d:displayname>'
            . '<d:current-user-privilege-set><d:privilege><d:read/></d:privilege>'
            . '<d:privilege><d:write/></d:privilege></d:current-user-privilege-set>',
        ));

        $calendars = $fixture->driver->discover(CalendarSource::ofIntegration(CalDavFixture::integration()));

        self::assertFalse($calendars[0]->isReadOnly);
    }

    public function testAPropertyTheServerSaidItDoesNotHaveIsAbsentRatherThanEmpty(): void
    {
        // A multistatus answers a PROPFIND for a property it does not have by
        // listing the empty element in a **404 propstat**. Read as a value,
        // that is indistinguishable from "the server enumerated my privileges
        // and I have none" — so every calendar on a server that omits
        // current-user-privilege-set would silently become read-only, and every
        // edit made on one would be stranded with nothing on screen to say why.
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->discoveryResponse()),
            CalDavFixture::multistatus(<<<'XML'
                <?xml version="1.0" encoding="utf-8"?>
                <d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
                  <d:response>
                    <d:href>/calendars/alice/personal/</d:href>
                    <d:propstat>
                      <d:prop>
                        <d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>
                        <d:displayname>Personal</d:displayname>
                      </d:prop>
                      <d:status>HTTP/1.1 200 OK</d:status>
                    </d:propstat>
                    <d:propstat>
                      <d:prop><d:current-user-privilege-set/><cal:calendar-timezone/></d:prop>
                      <d:status>HTTP/1.1 404 Not Found</d:status>
                    </d:propstat>
                  </d:response>
                </d:multistatus>
                XML),
        );

        $calendars = $fixture->driver->discover(CalendarSource::ofIntegration(CalDavFixture::integration()));

        self::assertSame('Personal', $calendars[0]->name);
        self::assertFalse($calendars[0]->isReadOnly, 'a property the server does not have says nothing about privileges');
    }

    public function testTheTimeZoneComesOutOfTheVtimezoneBlob(): void
    {
        // calendar-timezone is a whole VCALENDAR, not a zone name.
        $fixture = $this->fixture($this->home());

        $calendars = $fixture->driver->discover(CalendarSource::ofIntegration(CalDavFixture::integration()));

        self::assertSame('Europe/Berlin', $calendars[0]->timeZone);
        self::assertNull($calendars[1]->timeZone, 'a collection that says nothing gets the user\'s own zone later');
    }

    public function testATaskListIsNotOfferedAsACalendar(): void
    {
        // Apple-flavoured servers publish VTODO-only collections under the same
        // home. Offered as a calendar, one syncs nothing forever and looks
        // broken.
        $fixture = $this->fixture($this->collection(
            '<d:displayname>Reminders</d:displayname>'
            . '<cal:supported-calendar-component-set><cal:comp name="VTODO"/></cal:supported-calendar-component-set>',
        ));

        self::assertSame([], $fixture->driver->discover(CalendarSource::ofIntegration(CalDavFixture::integration())));
    }

    public function testACollectionWithNoNameFallsBackToItsAddress(): void
    {
        // Legal, and not rare on Radicale. The last path segment is what every
        // other client shows, and it is at least something the user recognises.
        $fixture = $this->fixture($this->collection('', '/calendars/alice/Holiday%20Plans/'));

        $calendars = $fixture->driver->discover(CalendarSource::ofIntegration(CalDavFixture::integration()));

        self::assertSame('Holiday Plans', $calendars[0]->name);
    }

    public function testNothingIsTickedByDefaultBecauseCalDavHasNoPrimaryCalendar(): void
    {
        $fixture = $this->fixture($this->home());

        foreach ($fixture->driver->discover(CalendarSource::ofIntegration(CalDavFixture::integration())) as $calendar) {
            self::assertFalse($calendar->isPrimary, 'no CalDAV property says which calendar is the main one');
        }
    }

    public function testASinglePastedCalendarIsAskedAboutWithDepthZero(): void
    {
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->collection('<d:displayname>Personal</d:displayname>')),
            CalDavFixture::multistatus($this->collection('<d:displayname>Personal</d:displayname>')),
        );

        $calendars = $fixture->driver->discover(CalendarSource::ofIntegration(
            CalDavFixture::integration('https://dav.example.com/calendars/alice/personal/'),
        ));

        self::assertCount(1, $calendars);
        // Depth 1 on a single collection would list its events as if they were
        // calendars.
        self::assertSame('0', $fixture->header(1, 'Depth'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** Discovery answers first, then the collection listing. */
    private function fixture(string $listing): CalDavFixture
    {
        return new CalDavFixture(
            CalDavFixture::multistatus($this->discoveryResponse()),
            CalDavFixture::multistatus($listing),
        );
    }

    private function discoveryResponse(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
              <d:response>
                <d:href>/</d:href>
                <d:propstat>
                  <d:prop>
                    <d:resourcetype><d:collection/></d:resourcetype>
                    <cal:calendar-home-set><d:href>/calendars/alice/</d:href></cal:calendar-home-set>
                  </d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
            </d:multistatus>
            XML;
    }

    /**
     * A realistic home listing: the home itself first (a plain collection), a
     * writable colour-carrying calendar with a zone, and a read-only one that
     * spells its colour as a word.
     */
    private function home(): string
    {
        $vtimezone = CalDavFixture::calendarData(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n",
        );

        return sprintf(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"
                           xmlns:ical="http://apple.com/ns/ical/" xmlns:cs="http://calendarserver.org/ns/">
              <d:response>
                <d:href>/calendars/alice/</d:href>
                <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop>
                <d:status>HTTP/1.1 200 OK</d:status></d:propstat>
              </d:response>
              <d:response>
                <d:href>/calendars/alice/personal/</d:href>
                <d:propstat>
                  <d:prop>
                    <d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>
                    <d:displayname>Personal</d:displayname>
                    <ical:calendar-color>#FF5733FF</ical:calendar-color>
                    <cal:calendar-timezone>%s</cal:calendar-timezone>
                    <cal:supported-calendar-component-set><cal:comp name="VEVENT"/><cal:comp name="VTODO"/></cal:supported-calendar-component-set>
                    <d:current-user-privilege-set>
                      <d:privilege><d:read/></d:privilege>
                      <d:privilege><d:write-content/></d:privilege>
                      <d:privilege><d:bind/></d:privilege>
                    </d:current-user-privilege-set>
                    <cs:getctag>http://dav.example.com/ns/sync/17</cs:getctag>
                  </d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
              <d:response>
                <d:href>/calendars/alice/team/</d:href>
                <d:propstat>
                  <d:prop>
                    <d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>
                    <d:displayname>Team</d:displayname>
                    <ical:calendar-color>seagreen</ical:calendar-color>
                    <d:current-user-privilege-set>
                      <d:privilege><d:read/></d:privilege>
                      <d:privilege><d:read-acl/></d:privilege>
                    </d:current-user-privilege-set>
                  </d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
                <d:propstat>
                  <d:prop><cal:calendar-timezone/></d:prop>
                  <d:status>HTTP/1.1 404 Not Found</d:status>
                </d:propstat>
              </d:response>
            </d:multistatus>
            XML, $vtimezone);
    }

    private function collection(string $props, string $href = '/calendars/alice/personal/'): string
    {
        return sprintf(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:ical="http://apple.com/ns/ical/">
              <d:response>
                <d:href>%s</d:href>
                <d:propstat>
                  <d:prop>
                    <d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>
                    %s
                  </d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
            </d:multistatus>
            XML, $href, $props);
    }
}
