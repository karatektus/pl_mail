<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\CalDav;

use App\Domain\Exception\CalendarSyncPermanentException;
use PHPUnit\Framework\TestCase;

/**
 * Finding the calendars behind an address a person typed.
 *
 * The claim: **what the user pasted is tried first, and the RFC 6764 bootstrap
 * only runs when it taught us nothing.** Every other client shows a "CalDAV
 * URL" somewhere and it is variously the server root, the principal, the
 * calendar home or one calendar — so a discovery that always started at
 * /.well-known/caldav would replace a correct, specific URL with whatever the
 * front page redirects to, which on shared hosting is somebody's marketing
 * site.
 *
 * The other claims here are about failing usefully. A rejected app password
 * must say so instead of arriving three steps later as "no calendar service
 * found", and an address inside the container network must be refused before a
 * request is made rather than after — which is the one test in this file that
 * is about security rather than about ergonomics.
 */
final class CalDavDiscoveryTest extends TestCase
{
    public function testTheWellKnownAddressLeadsThroughThePrincipalToTheCalendarHome(): void
    {
        $fixture = new CalDavFixture(
            CalDavFixture::redirect('https://dav.example.com/dav/'),
            CalDavFixture::multistatus($this->principalResponse('/dav/')),
            CalDavFixture::multistatus($this->homeResponse('/dav/principals/alice/')),
        );

        $endpoint = $fixture->discovery->endpointFor(CalDavFixture::integration('https://dav.example.com'));

        self::assertSame('https://dav.example.com/dav/calendars/alice/', $endpoint->calendarHome);
        self::assertSame('https://dav.example.com/dav/principals/alice/', $endpoint->principal);
        self::assertFalse($endpoint->isSingleCollection());

        self::assertSame('https://dav.example.com/.well-known/caldav', $fixture->url(0));
        self::assertSame('PROPFIND', $fixture->method(0), 'the bootstrap is a PROPFIND, not a GET');
        self::assertSame('https://dav.example.com/dav/', $fixture->url(1), 'the redirect is followed by hand');
        self::assertSame('https://dav.example.com/dav/principals/alice/', $fixture->url(2));
        self::assertSame('0', $fixture->header(0, 'Depth'), 'the bootstrap asks about one resource');
    }

    public function testAPastedCalendarUrlIsUsedAsItStandsRatherThanBootstrapped(): void
    {
        // The common case: somebody copied one calendar's address out of
        // Thunderbird. Walking up to its parent would offer them calendars they
        // did not ask for and, on a shared server, possibly somebody else's.
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->collectionResponse('/calendars/alice/personal/')),
        );

        $endpoint = $fixture->discovery->endpointFor(
            CalDavFixture::integration('https://dav.example.com/calendars/alice/personal/'),
        );

        self::assertTrue($endpoint->isSingleCollection());
        self::assertSame('https://dav.example.com/calendars/alice/personal/', $endpoint->collection);
        self::assertSame(1, $fixture->requestCount(), 'nothing else needed asking');
    }

    public function testAPastedPrincipalUrlSkipsTheWellKnownLookupEntirely(): void
    {
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->homeResponse('/dav/principals/alice/')),
        );

        $endpoint = $fixture->discovery->endpointFor(
            CalDavFixture::integration('https://dav.example.com/dav/principals/alice'),
        );

        self::assertSame('https://dav.example.com/dav/calendars/alice/', $endpoint->calendarHome);
        self::assertSame(1, $fixture->requestCount());
        self::assertStringNotContainsString('.well-known', $fixture->url(0));
    }

    public function testAnAddressInsideTheContainerNetworkIsRefusedBeforeAnyRequest(): void
    {
        // The SSRF guard, and the reason it is checked here rather than trusted
        // to the connect form: the sync sweep reaches this code with whatever
        // is in the database, months after the form validated anything.
        $fixture = new CalDavFixture();

        try {
            $fixture->discovery->endpointFor(CalDavFixture::integration('https://192.168.1.10/dav'));
            self::fail('a private address must not be requested');
        } catch (CalendarSyncPermanentException $e) {
            self::assertStringContainsString('private network', $e->getMessage());
        }

        self::assertSame(0, $fixture->requestCount(), 'the request must not be made at all');
    }

    public function testARedirectIntoTheContainerNetworkIsNotFollowed(): void
    {
        // This is the SSRF attack in full: the address the user gave is a
        // public host that passes every check, and the *server* then points us
        // at the cloud metadata endpoint. A hop is a URL somebody else chose,
        // so it is validated rather than trusted for having come from a host
        // that was — which is why this is the one place that follows redirects
        // by hand instead of letting the HTTP client do it.
        $fixture = new CalDavFixture(CalDavFixture::redirect('https://169.254.169.254/latest/meta-data/'));

        try {
            $fixture->discovery->endpointFor(CalDavFixture::integration('https://dav.example.com'));
            self::fail('a redirect into a private range must not be followed');
        } catch (CalendarSyncPermanentException $e) {
            self::assertStringContainsString('private network', $e->getMessage());
        }

        self::assertSame(1, $fixture->requestCount(), 'only the first, validated request may happen');
    }

    public function testPlainHttpIsRefusedUnlessAnAdministratorAllowedIt(): void
    {
        $fixture = new CalDavFixture();

        try {
            $fixture->discovery->endpointFor(CalDavFixture::integration('http://dav.example.com/dav'));
            self::fail('http must not be used without the flag');
        } catch (CalendarSyncPermanentException $e) {
            self::assertStringContainsString('https', $e->getMessage());
        }

        self::assertSame(0, $fixture->requestCount());
    }

    public function testARejectedAppPasswordSaysSoRatherThanReportingAMissingService(): void
    {
        // 401 is deliberately not in the "nothing here, try the next address"
        // list. Tolerating it would walk the whole bootstrap and end with "no
        // calendar service answered", sending the user to fix an address that
        // was right all along.
        $fixture = new CalDavFixture(CalDavFixture::status(401));

        try {
            $fixture->discovery->endpointFor(CalDavFixture::integration('https://dav.example.com/dav'));
            self::fail('a 401 must not be swallowed');
        } catch (CalendarSyncPermanentException $e) {
            self::assertSame(401, $e->getStatus());
            self::assertStringContainsString('app password', $e->getMessage());
            self::assertStringNotContainsString('app-password', $e->getMessage(), 'the credential itself must never be in a message');
        }
    }

    public function testAForbiddenWebRootIsAReasonToKeepLookingRatherThanToGiveUp(): void
    {
        // Plenty of servers refuse a PROPFIND on the web root while serving
        // CalDAV perfectly at /dav. Treating that 403 as final would report a
        // permissions failure for a connection that works.
        $fixture = new CalDavFixture(
            CalDavFixture::status(403),
            CalDavFixture::multistatus($this->homeResponse('/dav/')),
        );

        $endpoint = $fixture->discovery->endpointFor(CalDavFixture::integration('https://dav.example.com/dav'));

        self::assertSame('https://dav.example.com/dav/calendars/alice/', $endpoint->calendarHome);
        self::assertSame('https://dav.example.com/.well-known/caldav', $fixture->url(1));
    }

    public function testAnAddressWithNothingBehindItNamesItselfAndWhatToTryInstead(): void
    {
        $fixture = new CalDavFixture(
            CalDavFixture::status(404),
            CalDavFixture::status(404),
            CalDavFixture::status(404),
        );

        try {
            $fixture->discovery->endpointFor(CalDavFixture::integration('https://dav.example.com/wrong'));
            self::fail('an address with no calendar service must not be accepted');
        } catch (CalendarSyncPermanentException $e) {
            self::assertStringContainsString('https://dav.example.com/wrong', $e->getMessage());
            self::assertStringContainsString('CalDAV address', $e->getMessage());
        }
    }

    public function testAServerThatAnswersTheHomeDirectlyCostsOneRequest(): void
    {
        // The efficient path, and the one most servers take: current-user-
        // principal and calendar-home-set both come back from the first
        // PROPFIND, so the second round trip is skipped.
        $fixture = new CalDavFixture(
            CalDavFixture::multistatus($this->homeResponse('/dav/', withPrincipal: true)),
        );

        $endpoint = $fixture->discovery->endpointFor(CalDavFixture::integration('https://dav.example.com/dav'));

        self::assertSame('https://dav.example.com/dav/calendars/alice/', $endpoint->calendarHome);
        self::assertSame('https://dav.example.com/dav/principals/alice/', $endpoint->principal);
        self::assertSame(1, $fixture->requestCount());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** A server that names the principal and nothing else, which is the norm. */
    private function principalResponse(string $href): string
    {
        return sprintf(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
              <d:response>
                <d:href>%s</d:href>
                <d:propstat>
                  <d:prop>
                    <d:resourcetype><d:collection/></d:resourcetype>
                    <d:current-user-principal><d:href>/dav/principals/alice/</d:href></d:current-user-principal>
                  </d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
                <d:propstat>
                  <d:prop><cal:calendar-home-set/></d:prop>
                  <d:status>HTTP/1.1 404 Not Found</d:status>
                </d:propstat>
              </d:response>
            </d:multistatus>
            XML, $href);
    }

    private function homeResponse(string $href, bool $withPrincipal = false): string
    {
        $principal = true === $withPrincipal
            ? '<d:current-user-principal><d:href>/dav/principals/alice/</d:href></d:current-user-principal>'
            : '';

        return sprintf(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
              <d:response>
                <d:href>%s</d:href>
                <d:propstat>
                  <d:prop>
                    <d:resourcetype><d:collection/></d:resourcetype>
                    %s
                    <cal:calendar-home-set><d:href>/dav/calendars/alice/</d:href></cal:calendar-home-set>
                  </d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
            </d:multistatus>
            XML, $href, $principal);
    }

    private function collectionResponse(string $href): string
    {
        return sprintf(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
              <d:response>
                <d:href>%s</d:href>
                <d:propstat>
                  <d:prop>
                    <d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>
                    <d:displayname>Personal</d:displayname>
                  </d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
            </d:multistatus>
            XML, $href);
    }
}
