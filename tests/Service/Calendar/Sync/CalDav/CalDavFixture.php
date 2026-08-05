<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\CalDav;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Integration\Provider;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Integration\Integration;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Service\Calendar\Alert\AlertReader;
use App\Service\Calendar\RecurrenceRuleConverter;
use App\Service\Calendar\Sync\CalDav\CalDavCalendarDriver;
use App\Service\Calendar\Sync\CalDav\CalDavClient;
use App\Service\Calendar\Sync\CalDav\CalDavDiscovery;
use App\Service\Calendar\Sync\CalDav\CalDavEventConverter;
use App\Service\Calendar\Sync\CalDav\MultiStatusParser;
use App\Service\Integration\IntegrationUrlValidator;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The CalDAV driver assembled over a scripted transport, plus the entities and
 * XML it needs to be called at all.
 *
 * A helper class rather than an abstract test case, matching GoogleDriverFixture
 * and FakeCalendarSyncDriver: four test classes need the same driver over
 * different scripts, and inheritance would make the setup something each of
 * them overrides a piece of rather than something they all state.
 *
 * Every collaborator is real — the URL validator especially, because "an
 * internal address is refused" is a claim about the driver as it actually ships
 * and a doubled validator would assert it into existence. Only the provider
 * config repository is stood in for, and only because it is a Doctrine
 * repository whose answer here is always "no admin has pinned an address".
 *
 * Responses are replayed in order and each one remembers the request that
 * consumed it, which is how a test asserts that If-None-Match was really sent,
 * that the sync token went back out in the body, and that a delete of something
 * the remote never saw made no request at all. Nothing reaches the network, and
 * asking for more requests than were scripted fails loudly.
 */
final class CalDavFixture
{
    public readonly CalDavCalendarDriver $driver;
    public readonly CalDavDiscovery $discovery;
    public readonly MockHttpClient $http;

    /** @var list<MockResponse> in the order the driver will consume them */
    public readonly array $responses;

    public function __construct(MockResponse ...$responses)
    {
        $this->responses = array_values($responses);
        $this->http      = new MockHttpClient($this->responses);

        $parser = new MultiStatusParser();
        $client = new CalDavClient($this->http, new IntegrationUrlValidator(), self::configs(), $parser);

        $this->discovery = new CalDavDiscovery($client, $parser, new NullLogger());
        $this->driver    = new CalDavCalendarDriver(
            $client,
            $this->discovery,
            $parser,
            new CalDavEventConverter(new RecurrenceRuleConverter(), new AlertReader(new NullLogger())),
            new NullLogger(),
        );
    }

    // ── Responses ─────────────────────────────────────────────────────────────

    /** A multistatus, which is what almost every CalDAV request answers with. */
    public static function multistatus(string $xml, int $status = 207): MockResponse
    {
        return new MockResponse($xml, [
            'http_code'        => $status,
            'response_headers' => ['content-type' => 'application/xml; charset=utf-8'],
        ]);
    }

    /**
     * @param array<string,string> $headers
     */
    public static function status(int $status, array $headers = [], string $body = ''): MockResponse
    {
        return new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers]);
    }

    /** RFC 6764's bootstrap step: /.well-known/caldav points at the real path. */
    public static function redirect(string $location, int $status = 301): MockResponse
    {
        return self::status($status, ['location' => $location]);
    }

    /**
     * The DAV error body a server sends when the sync token is too old to
     * resume from. Wrapped in whichever status the caller's server uses — 403,
     * 409 and 400 are all in the wild.
     */
    public static function deadSyncToken(int $status = 403): MockResponse
    {
        return self::status($status, [], <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:error xmlns:d="DAV:"><d:valid-sync-token/></d:error>
            XML);
    }

    // ── Request assertions ────────────────────────────────────────────────────

    public function method(int $index): string
    {
        return $this->responses[$index]->getRequestMethod();
    }

    public function url(int $index): string
    {
        return $this->responses[$index]->getRequestUrl();
    }

    /**
     * The body the driver sent.
     *
     * Drained rather than cast, because the transport may hand a body on as a
     * generator — reading it as a string then yields '' and every assertion
     * about a request body passes vacuously.
     */
    public function body(int $index): string
    {
        $body = $this->responses[$index]->getRequestOptions()['body'] ?? '';

        if (true === is_string($body)) {
            return $body;
        }

        $buffer = '';

        if ($body instanceof \Closure) {
            while ('' !== ($chunk = $body(16372))) {
                $buffer .= $chunk;
            }

            return $buffer;
        }

        if (true === is_iterable($body)) {
            foreach ($body as $chunk) {
                $buffer .= (string) $chunk;
            }
        }

        return $buffer;
    }

    /**
     * One request header, case-insensitively: the transport normalises the case
     * and a test asserting on "If-Match" should not depend on which way it went.
     */
    public function header(int $index, string $name): ?string
    {
        $normalised = $this->responses[$index]->getRequestOptions()['normalized_headers'] ?? [];
        $line       = null;

        if (true === is_array($normalised)) {
            $entry = $normalised[mb_strtolower($name)][0] ?? null;
            $line  = true === is_string($entry) ? $entry : null;
        }

        if (null === $line) {
            return null;
        }

        return trim(mb_substr($line, mb_strlen($name) + 1));
    }

    public function requestCount(): int
    {
        return $this->http->getRequestsCount();
    }

    // ── Entities ──────────────────────────────────────────────────────────────

    public static function integration(string $baseUrl = 'https://dav.example.com'): Integration
    {
        $integration           = new Integration(new User(), Provider::CalDav, 'Home server');
        $integration->baseUrl  = $baseUrl;
        $integration->username = 'alice';
        $integration->secret   = 'app-password';

        return $integration;
    }

    public static function calendar(
        ?Integration $integration = null,
        string       $remoteId = 'https://dav.example.com/calendars/alice/personal/',
    ): Calendar {
        $calendar              = new Calendar();
        $calendar->usr         = new User();
        $calendar->integration = $integration ?? self::integration();
        $calendar->role        = CalendarRole::Remote;
        $calendar->remoteId    = $remoteId;

        return $calendar;
    }

    /**
     * @param array<string,mixed> $jscalendar
     */
    public static function event(
        string  $uid = 'standup-42',
        ?string $remoteId = null,
        ?string $etag = null,
        array   $jscalendar = [],
        bool    $isAllDay = false,
    ): CalendarEvent {
        $event             = new CalendarEvent();
        $event->uid        = $uid;
        $event->title      = 'Standup';
        $event->location   = 'Room 3';
        $event->startsAt   = new DateTimeImmutable('2026-08-04 08:00:00', new DateTimeZone('UTC'));
        $event->endsAt     = new DateTimeImmutable('2026-08-04 08:30:00', new DateTimeZone('UTC'));
        $event->timeZone   = 'Europe/Berlin';
        $event->isAllDay   = $isAllDay;
        $event->status     = EventStatus::Confirmed;
        $event->remoteId   = $remoteId;
        $event->remoteEtag = $etag;
        $event->jscalendar = $jscalendar;

        return $event;
    }

    // ── XML ───────────────────────────────────────────────────────────────────

    /** One VEVENT, as a server would hand it back inside calendar-data. */
    public static function ics(string $uid, string $summary, string $start = '20260804T080000Z'): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nBEGIN:VEVENT\r\n"
            . sprintf("UID:%s\r\nDTSTAMP:20260801T090000Z\r\nDTSTART:%s\r\nDTEND:20260804T083000Z\r\nSUMMARY:%s\r\n", $uid, $start, $summary)
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /** calendar-data has to be escaped to survive being XML text. */
    public static function calendarData(string $ics): string
    {
        return htmlspecialchars($ics, ENT_XML1);
    }

    /**
     * A repository that answers "no admin has pinned an address for CalDAV".
     *
     * An anonymous subclass rather than a PHPUnit stub so this fixture stays a
     * plain helper class: createStub() lives on TestCase, and threading a
     * doubled repository in from four test classes would put the one
     * uninteresting collaborator into every one of their signatures.
     */
    private static function configs(): IntegrationProviderConfigRepository
    {
        return new class extends IntegrationProviderConfigRepository {
            public function __construct()
            {
                // Deliberately does not call the parent: it wants a
                // ManagerRegistry, and nothing on this path touches Doctrine.
            }

            public function findOneByProvider(Provider $provider): ?IntegrationProviderConfig
            {
                return null;
            }
        };
    }
}
