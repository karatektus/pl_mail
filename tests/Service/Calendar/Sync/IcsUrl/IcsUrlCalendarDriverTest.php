<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\IcsUrl;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Service\Calendar\Ics\IcsDocumentReader;
use App\Service\Calendar\Sync\CalDav\CalDavEventConverter;
use App\Service\Calendar\Sync\IcsUrl\IcsFeedClient;
use App\Service\Calendar\Sync\IcsUrl\IcsUrlCalendarDriver;
use App\Service\Calendar\Sync\IcsUrl\IcsUrlNormaliser;
use App\Service\Integration\IntegrationUrlValidator;
use DateTimeZone;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Reading a calendar that is a file at an address.
 *
 * Four claims, and each of them is a thing that goes wrong quietly if it is
 * dropped.
 *
 * **An unchanged feed costs one 304.** A feed has no delta feed, no change token
 * and no per-event ids, so the only cheap question available is HTTP's own:
 * present the ETag and the Last-Modified from last time and let the server say
 * "no". Without it, polling a holiday calendar every fifteen minutes means
 * downloading and re-parsing it every fifteen minutes forever.
 *
 * **A changed feed surrenders the token rather than reporting a listing.** The
 * engine only deletes local rows a listing did not mention when it asked with a
 * null token. A driver that returned the listing against a live token would
 * apply every edit and keep every cancelled fixture on the calendar for good —
 * the same trap CalDAV's ctag fallback avoids the same way.
 *
 * **A redirect is validated before it is followed.** A perfectly public feed
 * host that answers `302 Location: http://169.254.169.254/…` turns a scheduled
 * poll into a read of the cloud metadata endpoint. Letting the HTTP client chase
 * redirects is exactly the shape of that hole, which is why max_redirects is 0
 * and every hop goes back through the validator.
 *
 * **A feed is read-only, always, and refuses writes loudly.** There is no method
 * that would write to a file at a URL. push() and delete() throwing is what
 * turns an engine bug into a visible failure instead of edits that vanish on the
 * next sweep with no trace.
 *
 * A KernelTestCase for one collaborator only: CalDavEventConverter is the shared
 * VEVENT mapper and is assembled from services of its own, so it comes from the
 * container rather than being constructed here — a test that built it by hand
 * would need rewriting every time it grew a dependency, and would then be
 * asserting about a converter that is not the one that ships. Nothing here
 * touches the database, so there is no transaction to roll back.
 */
final class IcsUrlCalendarDriverTest extends KernelTestCase
{
    private const string FEED_URL = 'https://feeds.example.com/holidays.ics';

    private CalDavEventConverter $converter;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->converter = self::getContainer()->get(CalDavEventConverter::class);
    }

    public function testAnUnchangedFeedIsA304AndReportsNoChanges(): void
    {
        $http   = new MockHttpClient([new MockResponse('', ['http_code' => 304])]);
        $driver = $this->driver($http);

        $changes = $driver->pull($this->calendar(), $this->token('"v1"', 'Wed, 05 Aug 2026 10:00:00 GMT'));

        self::assertSame([], $changes->events);
        self::assertFalse($changes->requiresFullResync);
        self::assertSame(
            $this->token('"v1"', 'Wed, 05 Aug 2026 10:00:00 GMT'),
            $changes->nextSyncToken,
            'a 304 carries no validators, so the stored position must be handed straight back',
        );
    }

    /**
     * The conditional request itself. Without these two headers the server has
     * nothing to answer 304 against, and every poll is a full download — the
     * test above would still pass, because it scripts the 304 rather than
     * earning it.
     */
    public function testTheStoredValidatorsAreActuallyPresented(): void
    {
        $response = new MockResponse('', ['http_code' => 304]);
        $driver   = $this->driver(new MockHttpClient([$response]));

        $driver->pull($this->calendar(), $this->token('"v1"', 'Wed, 05 Aug 2026 10:00:00 GMT'));

        self::assertSame('"v1"', $this->header($response, 'If-None-Match'));
        self::assertSame('Wed, 05 Aug 2026 10:00:00 GMT', $this->header($response, 'If-Modified-Since'));
    }

    public function testAChangedFeedSurrendersTheTokenRatherThanReportingAListing(): void
    {
        $http   = new MockHttpClient([$this->feed($this->twoEvents())]);
        $driver = $this->driver($http);

        $changes = $driver->pull($this->calendar(), $this->token('"v1"', ''));

        self::assertTrue(
            $changes->requiresFullResync,
            'a listing against a live token would keep every removed event forever',
        );
        self::assertSame([], $changes->events);
    }

    public function testAFullReadMapsEveryEventAndRemembersBothValidators(): void
    {
        $http   = new MockHttpClient([$this->feed($this->twoEvents(), '"v2"', 'Thu, 06 Aug 2026 09:00:00 GMT')]);
        $driver = $this->driver($http);

        $changes = $driver->pull($this->calendar(), null);

        self::assertCount(2, $changes->events);

        [$meeting, $holiday] = $changes->events;

        // The UID is the remote id here: a file has no addresses in it, and the
        // UID is what makes the same meeting recognisable when it also arrived
        // as an invitation in the mailbox.
        self::assertSame('meeting-1', $meeting->remoteId);
        self::assertSame('meeting-1', $meeting->uid);
        self::assertSame('Standup', $meeting->jscalendar['title'] ?? null);
        self::assertNull($meeting->etag, 'a file has no per-event version marker to claim one from');

        self::assertSame('holiday-1', $holiday->remoteId);

        self::assertSame(
            "\"v2\"\x1fThu, 06 Aug 2026 09:00:00 GMT",
            $changes->nextSyncToken,
        );
    }

    /**
     * An all-day event is a DATE, not a DATETIME at local midnight. Read as the
     * second it shifts by a day for everybody east or west of the zone it was
     * written in — which is how a birthday arrives on the wrong day.
     */
    public function testAnAllDayEventStaysAWholeDayRatherThanMidnightInSomeZone(): void
    {
        $driver  = $this->driver(new MockHttpClient([$this->feed($this->twoEvents())]));
        $changes = $driver->pull($this->calendar(), null);

        $holiday = $changes->events[1];

        self::assertTrue($holiday->jscalendar['showWithoutTime'] ?? false);
        self::assertSame('2026-05-01T00:00:00', $holiday->startsAt?->format('Y-m-d\TH:i:s'));
        self::assertArrayNotHasKey(
            'timeZone',
            $holiday->jscalendar,
            'an all-day event is floating; naming a zone is what shifts it by a day',
        );
    }

    /**
     * The SSRF guard, at the hop the URL validator on the first address cannot
     * see. Refused rather than followed, and permanently — no amount of retrying
     * makes a metadata endpoint a calendar.
     */
    public function testARedirectIntoThePrivateNetworkIsRefusedRatherThanFollowed(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', [
                'http_code'        => 302,
                // https, not http: the validator refuses plain http first, and
                // this test is about the *address* being internal rather than
                // about the scheme. A redirect over TLS to a private range is
                // also the shape that actually gets through everything else.
                'response_headers' => ['location' => 'https://169.254.169.254/latest/meta-data/'],
            ]),
        ]);

        $this->expectException(CalendarSyncPermanentException::class);
        $this->expectExceptionMessageMatches('/private network/');

        $this->driver($http)->pull($this->calendar(), null);
    }

    /** A redirect that stays outside is followed, or every http-to-https feed breaks. */
    public function testAnOrdinaryRedirectIsFollowed(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', [
                'http_code'        => 301,
                'response_headers' => ['location' => 'https://cdn.example.com/holidays.ics'],
            ]),
            $this->feed($this->twoEvents()),
        ]);

        self::assertCount(2, $this->driver($http)->pull($this->calendar(), null)->events);
    }

    public function testDiscoverAnswersOneCalendarThatIsAlwaysReadOnly(): void
    {
        $http     = new MockHttpClient([$this->feed($this->twoEvents())]);
        $calendars = $this->driver($http)->discover(CalendarSource::ofIntegration($this->integration()));

        self::assertCount(1, $calendars);
        self::assertSame(IcsUrlCalendarDriver::REMOTE_ID, $calendars[0]->remoteId);
        self::assertSame('German holidays', $calendars[0]->name, 'X-WR-CALNAME names the calendar');
        self::assertSame('Europe/Berlin', $calendars[0]->timeZone);
        self::assertTrue($calendars[0]->isReadOnly, 'nothing can write to a file at a URL');
        self::assertFalse($calendars[0]->isPrimary);
    }

    /** A feed that does not name itself is named after its address, not "Calendar". */
    public function testAFeedWithNoNameIsNamedAfterItsAddress(): void
    {
        $http      = new MockHttpClient([$this->feed($this->twoEvents(), namedCalendar: false)]);
        $calendars = $this->driver($http)->discover(CalendarSource::ofIntegration($this->integration()));

        self::assertSame('holidays', $calendars[0]->name);
    }

    public function testPushingToASubscribedCalendarIsRefusedRatherThanSilentlyIgnored(): void
    {
        $this->expectException(CalendarSyncPermanentException::class);

        $this->driver(new MockHttpClient([]))->push($this->calendar(), new CalendarEvent());
    }

    public function testDeletingFromASubscribedCalendarIsRefusedRatherThanSilentlyIgnored(): void
    {
        $this->expectException(CalendarSyncPermanentException::class);

        $this->driver(new MockHttpClient([]))->delete($this->calendar(), new CalendarEvent());
    }

    /** Only its own provider, or it silently steals another driver's calendars. */
    public function testItClaimsOnlyItsOwnProvider(): void
    {
        $driver = $this->driver(new MockHttpClient([]));

        self::assertTrue($driver->supports(Provider::Ics));
        self::assertFalse($driver->supports(Provider::CalDav));
        self::assertFalse($driver->supports(CalendarSource::ofIntegration(
            new Integration(new User(), Provider::CalDav, 'Home server'),
        )));
    }

    /**
     * A feed with no validators at all stores no token, so the next read is a
     * full one. A token that said nothing would still be a token: the next poll
     * would take the "we have a position" branch, find the feed changed — it
     * cannot tell otherwise — and ask for a resync on every single sweep.
     */
    public function testAFeedWithNoValidatorsStoresNoPositionRatherThanAnEmptyOne(): void
    {
        $http    = new MockHttpClient([$this->feed($this->twoEvents(), etag: null, lastModified: null)]);
        $changes = $this->driver($http)->pull($this->calendar(), null);

        self::assertNull($changes->nextSyncToken);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function driver(MockHttpClient $http): IcsUrlCalendarDriver
    {
        // The real validator, because "an internal address is refused" is a
        // claim about the driver as it ships and a doubled one would assert it
        // into existence.
        $normaliser = new IcsUrlNormaliser(new IntegrationUrlValidator());

        return new IcsUrlCalendarDriver(
            new IcsFeedClient($http, $normaliser),
            $normaliser,
            new IcsDocumentReader(),
            $this->converter,
            new NullLogger(),
        );
    }

    private function integration(string $url = self::FEED_URL): Integration
    {
        $integration          = new Integration(new User(), Provider::Ics, 'Holidays');
        $integration->baseUrl = $url;

        return $integration;
    }

    private function calendar(): Calendar
    {
        $calendar              = new Calendar();
        $calendar->usr         = new User();
        $calendar->integration = $this->integration();
        $calendar->role        = CalendarRole::Remote;
        $calendar->remoteId    = IcsUrlCalendarDriver::REMOTE_ID;
        $calendar->isReadOnly  = true;

        return $calendar;
    }

    private function token(string $etag, string $lastModified): string
    {
        return $etag . "\x1f" . $lastModified;
    }

    private function feed(
        string  $body,
        ?string $etag = '"v1"',
        ?string $lastModified = 'Wed, 05 Aug 2026 10:00:00 GMT',
        bool    $namedCalendar = true,
    ): MockResponse {
        $headers = ['content-type' => 'text/calendar; charset=utf-8'];

        if (null !== $etag) {
            $headers['etag'] = $etag;
        }

        if (null !== $lastModified) {
            $headers['last-modified'] = $lastModified;
        }

        return new MockResponse(
            true === $namedCalendar ? $body : str_replace("X-WR-CALNAME:German holidays\r\n", '', $body),
            ['http_code' => 200, 'response_headers' => $headers],
        );
    }

    private function header(MockResponse $response, string $name): ?string
    {
        $normalised = $response->getRequestOptions()['normalized_headers'] ?? [];

        if (false === is_array($normalised)) {
            return null;
        }

        $line = $normalised[mb_strtolower($name)][0] ?? null;

        return true === is_string($line) ? trim(mb_substr($line, mb_strlen($name) + 1)) : null;
    }

    /** One timed meeting and one all-day holiday, which is what a feed is made of. */
    private function twoEvents(): string
    {
        return "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//Test//EN\r\n"
            . "X-WR-CALNAME:German holidays\r\n"
            . "X-WR-TIMEZONE:Europe/Berlin\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:meeting-1\r\n"
            . "DTSTAMP:20260101T000000Z\r\n"
            . "DTSTART;TZID=Europe/Berlin:20260810T100000\r\n"
            . "DTEND;TZID=Europe/Berlin:20260810T110000\r\n"
            . "SUMMARY:Standup\r\n"
            . "END:VEVENT\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:holiday-1\r\n"
            . "DTSTAMP:20260101T000000Z\r\n"
            . "DTSTART;VALUE=DATE:20260501\r\n"
            . "DTEND;VALUE=DATE:20260502\r\n"
            . "SUMMARY:Labour Day\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }
}
