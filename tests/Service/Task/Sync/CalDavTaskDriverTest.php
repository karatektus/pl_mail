<?php

declare(strict_types=1);

namespace App\Tests\Service\Task\Sync;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Entity\Task\Task;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use App\Infrastructure\Setup\GeneratedSecretsFile;
use App\Repository\Integration\IntegrationProviderConfig\IntegrationProviderConfigRepository;
use App\Service\Calendar\Sync\CalDav\CalDavClient;
use App\Service\Calendar\Sync\CalDav\CalDavCollectionReader;
use App\Service\Calendar\Sync\CalDav\CalDavDiscovery;
use App\Service\Calendar\Sync\CalDav\MultiStatusParser;
use App\Service\Integration\IntegrationUrlValidator;
use App\Service\Monitoring\WorkerRestartSignal;
use App\Service\Setup\PublicUrlSetting;
use App\Service\Task\Sync\CalDav\CalDavTaskConverter;
use App\Service\Task\Sync\CalDav\CalDavTaskDriver;
use App\Service\User\UserTimezoneResolver;
use App\Tests\Service\Calendar\Sync\CalDav\CalDavFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Task lists are the collections that say they hold tasks, and a mixed collection
 * gives this driver its VTODOs and the calendar its VEVENTs.
 *
 * The transport is scripted and every collaborator is real, CalDavFixture's way,
 * because what is under test is the requests that go out and what their answers
 * become. The answers are written to the protocol, not captured from a real server.
 */
final class CalDavTaskDriverTest extends TestCase
{
    private const string BASE = 'https://dav.example.com/calendars/alice/';

    public function testDiscoveryOffersOnlyCollectionsThatHoldTasks(): void
    {
        [$driver] = $this->driver(
            CalDavFixture::multistatus($this->home()),
            CalDavFixture::multistatus($this->collections()),
        );

        $lists = $driver->discover(CalendarSource::ofIntegration(CalDavFixture::integration()));

        self::assertSame(['Todo only', 'Mixed', 'Unrestricted', 'Shared read only'], array_map(static fn ($list) => $list->name, $lists));
        self::assertTrue($lists[3]->isReadOnly);
        self::assertFalse($lists[0]->isReadOnly);
        self::assertSame('#ff0000', $lists[0]->color, 'the alpha channel is dropped');
    }

    public function testAMixedCollectionGivesThisDriverItsTodosAndDeletions(): void
    {
        [$driver] = $this->driver(
            CalDavFixture::multistatus($this->probe(syncCollection: true)),
            CalDavFixture::multistatus($this->syncReport()),
        );

        $changes = $driver->pull($this->list(), 'token-1');

        self::assertCount(2, $changes->tasks, 'the VEVENT beside them is the calendar\'s');
        self::assertSame('Task', $changes->tasks[0]->title);
        self::assertSame('"etag-1"', $changes->tasks[0]->etag);
        self::assertTrue($changes->tasks[1]->isDeleted);
        self::assertSame('token-2', $changes->nextSyncToken);
    }

    public function testAServerWithoutSyncCollectionFiltersItsListingOnTodos(): void
    {
        [$driver, $responses] = $this->driver(
            CalDavFixture::multistatus($this->probe(syncCollection: false, ctag: 'c1')),
            CalDavFixture::multistatus($this->syncReport()),
        );

        $driver->pull($this->list(), null);

        self::assertStringContainsString('name="VTODO"', $this->body($responses[1]));
    }

    public function testAMovedCtagAsksForAFullReadRatherThanGuessingAtDeletions(): void
    {
        [$driver] = $this->driver(CalDavFixture::multistatus($this->probe(syncCollection: false, ctag: 'c2')));

        self::assertTrue($driver->pull($this->list(), 'c1')->requiresFullResync);
    }

    public function testACreateGuardsAgainstAnOverwriteAndGoesToAUidNamedAddress(): void
    {
        [$driver, $responses] = $this->driver(new MockResponse('', ['http_code' => 201, 'response_headers' => ['etag' => '"new"']]));

        $task        = new Task();
        $task->uid   = 'a/b c';
        $task->title = 'New';

        $result = $driver->push($this->list(), $task);

        self::assertSame('PUT', $responses[0]->getRequestMethod());
        self::assertStringEndsWith('/tasks/a%2Fb%20c.ics', $responses[0]->getRequestUrl());
        self::assertSame('"new"', $result->etag);
        self::assertStringContainsString('SUMMARY:New', (string) $result->payload);
        self::assertSame($result->payload, $this->body($responses[0]), 'what went out is the copy the next edit builds on');
        self::assertSame('If-None-Match: *', $responses[0]->getRequestOptions()['normalized_headers']['if-none-match'][0]);
    }

    public function testAnUpdateThatLostTheRaceIsAResyncOfThatRow(): void
    {
        [$driver] = $this->driver(new MockResponse('', ['http_code' => 412]));

        $task             = new Task();
        $task->uid        = 'u';
        $task->title      = 'T';
        $task->remoteId   = self::BASE . 'tasks/u.ics';
        $task->remoteEtag = '"old"';

        try {
            $driver->push($this->list(), $task);
            self::fail('A 412 was taken for a success.');
        } catch (CalendarResyncRequiredException $e) {
            self::assertSame(412, $e->getStatus());
        }
    }

    public function testDeletingAnAlreadyGoneTaskSucceeds(): void
    {
        [$driver] = $this->driver(new MockResponse('', ['http_code' => 404]));

        $task           = new Task();
        $task->remoteId = self::BASE . 'tasks/u.ics';

        $driver->delete($this->list(), $task);

        $this->addToAssertionCount(1);
    }

    public function testATaskTheServerNeverSawIsNeverSentADelete(): void
    {
        // No scripted answer at all: a request would throw on an empty transport.
        [$driver] = $this->driver();

        $driver->delete($this->list(), new Task());

        $this->addToAssertionCount(1);
    }

    public function testItSpeaksForCaldavAndNextcloudConnectionsAlone(): void
    {
        [$driver] = $this->driver();

        self::assertTrue($driver->supports(CalendarSource::ofIntegration(CalDavFixture::integration())));
    }

    /** @return array{CalDavTaskDriver, list<MockResponse>} */
    private function driver(MockResponse ...$responses): array
    {
        $responses = array_values($responses);
        $parser    = new MultiStatusParser();
        $client    = new CalDavClient(new MockHttpClient($responses), new IntegrationUrlValidator(), $this->configs(), $parser);

        return [
            new CalDavTaskDriver(
                $client,
                new CalDavDiscovery($client, $parser, new NullLogger()),
                new CalDavCollectionReader($client, $parser, new NullLogger()),
                new CalDavTaskConverter(),
                new UserTimezoneResolver('UTC'),
                new PublicUrlSetting(
                    new GeneratedSecretsFile('/nonexistent'),
                    new WorkerRestartSignal(new ArrayAdapter()),
                    new NullLogger(),
                ),
                new NullLogger(),
            ),
            $responses,
        ];
    }

    /** CalDavFixture keeps the one stand-in for this; reached here rather than copied. */
    private function configs(): IntegrationProviderConfigRepository
    {
        return (new ReflectionMethod(CalDavFixture::class, 'configs'))->invoke(null);
    }

    private function list(): TaskList
    {
        $list              = new TaskList();
        $list->usr         = new User();
        $list->integration = CalDavFixture::integration();
        $list->remoteId    = self::BASE . 'tasks/';

        return $list;
    }

    private function body(MockResponse $response): string
    {
        $body = $response->getRequestOptions()['body'] ?? '';

        if (true === is_string($body)) {
            return $body;
        }

        $buffer = '';

        while ('' !== ($chunk = $body(16372))) {
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function home(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
              <d:response><d:href>/</d:href><d:propstat><d:prop>
                <cal:calendar-home-set><d:href>/calendars/alice/</d:href></cal:calendar-home-set>
              </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>
            </d:multistatus>
            XML;
    }

    private function collections(): string
    {
        $collection = static fn (string $path, string $name, string $components, string $privilege, string $color = ''): string => sprintf(
            '<d:response><d:href>/calendars/alice/%s/</d:href><d:propstat><d:prop>'
            . '<d:resourcetype><d:collection/><cal:calendar/></d:resourcetype><d:displayname>%s</d:displayname>%s'
            . '<d:current-user-privilege-set><d:privilege><d:%s/></d:privilege></d:current-user-privilege-set>%s'
            . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>',
            $path,
            $name,
            '' === $components ? '' : sprintf('<cal:supported-calendar-component-set>%s</cal:supported-calendar-component-set>', $components),
            $privilege,
            '' === $color ? '' : sprintf('<ical:calendar-color>%s</ical:calendar-color>', $color),
        );

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:ical="http://apple.com/ns/ical/">'
            . '<d:response><d:href>/calendars/alice/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
            . $collection('todo', 'Todo only', '<cal:comp name="VTODO"/>', 'write', '#FF0000FF')
            . $collection('events', 'Events only', '<cal:comp name="VEVENT"/>', 'write')
            . $collection('mixed', 'Mixed', '<cal:comp name="VEVENT"/><cal:comp name="VTODO"/>', 'write')
            . $collection('any', 'Unrestricted', '', 'write')
            . $collection('shared', 'Shared read only', '<cal:comp name="VTODO"/>', 'read')
            . '</d:multistatus>';
    }

    private function probe(bool $syncCollection, ?string $ctag = null): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/">'
            . '<d:response><d:href>/calendars/alice/tasks/</d:href><d:propstat><d:prop>'
            . '<d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>%s%s'
            . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>',
            true === $syncCollection ? '<d:supported-report-set><d:supported-report><d:report><d:sync-collection/></d:report></d:supported-report></d:supported-report-set>' : '',
            null === $ctag ? '' : sprintf('<cs:getctag>%s</cs:getctag>', $ctag),
        );
    }

    private function syncReport(): string
    {
        $todo  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:t1\r\nSUMMARY:Task\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
        $event = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:e1\r\nSUMMARY:Event\r\nDTSTART:20260601T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $resource = static fn (string $name, string $ics, string $etag): string => sprintf(
            '<d:response><d:href>/calendars/alice/tasks/%s.ics</d:href><d:propstat><d:prop><d:getetag>%s</d:getetag><cal:calendar-data>%s</cal:calendar-data></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>',
            $name,
            htmlspecialchars($etag, ENT_XML1),
            htmlspecialchars($ics, ENT_XML1),
        );

        return '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">'
            . $resource('t1', $todo, '"etag-1"')
            . $resource('e1', $event, '"etag-2"')
            . '<d:response><d:href>/calendars/alice/tasks/gone.ics</d:href><d:status>HTTP/1.1 404 Not Found</d:status></d:response>'
            . '<d:sync-token>token-2</d:sync-token></d:multistatus>';
    }
}
