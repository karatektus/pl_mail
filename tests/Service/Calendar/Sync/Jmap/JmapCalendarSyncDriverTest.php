<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\Jmap;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\Enum\Account\IncomingProtocol;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Mail\Account;
use App\Infrastructure\JmapClient\JmapCapability;
use App\Infrastructure\JmapClient\JmapClient;
use App\Service\Calendar\Sync\Jmap\JmapCalendarSyncDriver;
use App\Service\User\UserTimezoneResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * JMAP calendars through the sync driver contract (#27).
 *
 * What the engine relies on, stated against a scripted server: the object it
 * is handed is JSCalendar without the JMAP envelope, the instants beside it
 * are right in the event's own zone, an event that left this calendar is a
 * tombstone here, and a state the server can no longer answer from asks for
 * a full read rather than failing the calendar for good.
 */
final class JmapCalendarSyncDriverTest extends TestCase
{
    /** @var array<string, callable(array<string, mixed>): array{0: string, 1: array<string, mixed>}> */
    private array $methods = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $calls = [];

    public function testAFullReadHandsOverJsCalendarWithoutTheEnvelope(): void
    {
        $this->on('CalendarEvent/query', static fn (): array => ['ids' => ['ev1']]);
        $this->on('CalendarEvent/get', static fn (): array => ['state' => 's5', 'list' => [[
            'id'          => 'ev1',
            'calendarIds' => ['cal1' => true],
            'isDraft'     => false,
            '@type'       => 'Event',
            'uid'         => 'meeting@example.test',
            'title'       => 'Planning',
            'start'       => '2026-07-01T10:00:00',
            'timeZone'    => 'Europe/Berlin',
            'duration'    => 'PT1H',
        ]]]);

        $changes = $this->driver()->pull($this->calendar(), null);

        self::assertSame('s5', $changes->nextSyncToken, 'the state the read was taken at is where changes start');
        self::assertCount(1, $changes->events);

        $event = $changes->events[0];

        self::assertSame('ev1', $event->remoteId);
        self::assertSame('meeting@example.test', $event->uid);
        self::assertArrayNotHasKey('calendarIds', $event->jscalendar);
        self::assertArrayNotHasKey('id', $event->jscalendar);
        self::assertSame('Planning', $event->jscalendar['title']);
        self::assertSame('2026-07-01T08:00:00+00:00', $event->startsAt?->format('c'));
        self::assertSame('2026-07-01T09:00:00+00:00', $event->endsAt?->format('c'));
    }

    /** An hour on the night the clocks go back is an hour, not two. */
    public function testTheDurationIsAddedInTheEventsOwnZone(): void
    {
        [$start, $end] = JmapCalendarSyncDriver::instants([
            'start'    => '2026-10-25T02:30:00',
            'timeZone' => 'Europe/Berlin',
            'duration' => 'PT1H',
        ], 'UTC');

        self::assertSame(3600, $end->getTimestamp() - $start->getTimestamp());
    }

    /** All-day is floating at midnight UTC here, so its day is the day it says. */
    public function testAnAllDayEventIsMidnightUtcOfItsDate(): void
    {
        [$start, $end] = JmapCalendarSyncDriver::instants([
            'start'           => '2026-10-27T00:00:00',
            'showWithoutTime' => true,
            'duration'        => 'P1D',
        ], 'Europe/Berlin');

        self::assertSame('2026-10-27T00:00:00+00:00', $start->format('c'));
        self::assertSame('2026-10-28T00:00:00+00:00', $end->format('c'));
    }

    public function testAnEventMovedToAnotherCalendarIsATombstoneHere(): void
    {
        $this->on('CalendarEvent/changes', static fn (): array => [
            'newState' => 's6', 'hasMoreChanges' => false,
            'created'  => [], 'updated' => ['ev1', 'ev2'], 'destroyed' => ['ev3'],
        ]);
        $this->on('CalendarEvent/get', static fn (): array => ['state' => 's6', 'list' => [
            ['id' => 'ev1', 'calendarIds' => ['cal1' => true], 'uid' => 'a', 'start' => '2026-07-01T10:00:00', 'timeZone' => 'UTC', 'duration' => 'PT1H'],
            ['id' => 'ev2', 'calendarIds' => ['cal2' => true], 'uid' => 'b', 'start' => '2026-07-01T10:00:00', 'timeZone' => 'UTC', 'duration' => 'PT1H'],
        ]]);

        $changes = $this->driver()->pull($this->calendar(), 's5');
        $byId    = [];

        foreach ($changes->events as $event) {
            $byId[$event->remoteId] = $event->isDeleted;
        }

        self::assertSame(['ev1' => false, 'ev2' => true, 'ev3' => true], $byId);
        self::assertSame('s6', $changes->nextSyncToken);
    }

    public function testAStateTheServerCannotAnswerFromAsksForAFullRead(): void
    {
        $this->on('CalendarEvent/changes', static fn (): array => ['error', ['type' => 'cannotCalculateChanges']]);

        self::assertTrue($this->driver()->pull($this->calendar(), 's1')->requiresFullResync);
    }

    public function testACreateIsFiledInThisCalendarUnderItsUid(): void
    {
        $this->on('CalendarEvent/set', static fn (): array => ['created' => ['e' => ['id' => 'new1']]]);

        $event             = new CalendarEvent();
        $event->uid        = 'local@plmail.test';
        $event->jscalendar = ['@type' => 'Event', 'title' => 'Dentist', 'start' => '2026-07-02T09:00:00', 'timeZone' => 'UTC', 'duration' => 'PT30M'];

        $result = $this->driver()->push($this->calendar(), $event);

        self::assertSame('new1', $result->remoteId);

        $create = $this->calls[0][1]['create']['e'];

        self::assertSame(['cal1' => true], $create['calendarIds']);
        self::assertSame('local@plmail.test', $create['uid']);
    }

    /**
     * The draft's single recurrenceRule on the wire, RFC 8984's list in
     * storage. Stalwart refuses `recurrenceRules` and `excludedRecurrenceRules`
     * in any form, null included, and an update clears what it does not set:
     * sent as they were, every edit was refused and the event given up.
     */
    public function testAnUpdateClearsTheRuleInTheDraftsShape(): void
    {
        $this->on('CalendarEvent/set', static fn (): array => ['updated' => ['ev1' => null]]);

        $event             = new CalendarEvent();
        $event->remoteId   = 'ev1';
        $event->jscalendar = ['@type' => 'Event', 'title' => 'Dentist', 'start' => '2026-07-02T09:00:00', 'timeZone' => 'UTC', 'duration' => 'PT30M'];

        $this->driver()->push($this->calendar(), $event);

        $update = $this->calls[0][1]['update']['ev1'];

        self::assertArrayHasKey('recurrenceRule', $update);
        self::assertNull($update['recurrenceRule']);
        self::assertArrayNotHasKey('recurrenceRules', $update);
        self::assertArrayNotHasKey('excludedRecurrenceRules', $update);
    }

    public function testARepeatingEventIsSentWithOneRuleAndReadBackAsTheList(): void
    {
        $rule = ['@type' => 'RecurrenceRule', 'frequency' => 'weekly'];

        $this->on('CalendarEvent/set', static fn (): array => ['created' => ['e' => ['id' => 'new1']]]);

        $event             = new CalendarEvent();
        $event->uid        = 'weekly@plmail.test';
        $event->jscalendar = ['@type' => 'Event', 'title' => 'Stand-up', 'start' => '2026-07-06T09:00:00', 'timeZone' => 'UTC', 'duration' => 'PT15M', 'recurrenceRules' => [$rule]];

        $this->driver()->push($this->calendar(), $event);

        $create = $this->calls[0][1]['create']['e'];

        self::assertSame($rule, $create['recurrenceRule']);
        self::assertArrayNotHasKey('recurrenceRules', $create);

        $this->on('CalendarEvent/query', static fn (): array => ['ids' => ['new1']]);
        $this->on('CalendarEvent/get', static fn (): array => ['state' => 's7', 'list' => [[
            'id' => 'new1', 'calendarIds' => ['cal1' => true], 'uid' => 'weekly@plmail.test',
            'start' => '2026-07-06T09:00:00', 'timeZone' => 'UTC', 'duration' => 'PT15M', 'recurrenceRule' => $rule,
        ]]]);

        $pulled = $this->driver()->pull($this->calendar(), null)->events[0]->jscalendar;

        self::assertSame([$rule], $pulled['recurrenceRules']);
        self::assertArrayNotHasKey('recurrenceRule', $pulled);
    }

    public function testAnAccountThatIsNotJmapIsNotThisDriversToAnswer(): void
    {
        $account = new Account();

        self::assertFalse($this->driver()->supports(CalendarSource::ofAccount($account)));
        self::assertTrue($this->driver()->supports(CalendarSource::ofAccount($this->account())));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param callable(array<string, mixed>): array<mixed> $answer the arguments,
     *        or a ['error', …] pair to answer with a method error
     */
    private function on(string $method, callable $answer): void
    {
        $this->methods[$method] = $answer;
    }

    private function driver(): JmapCalendarSyncDriver
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (true === str_ends_with($url, '/.well-known/jmap')) {
                return new MockResponse(json_encode([
                    'capabilities'    => [JmapCapability::CORE => [], JmapCapability::MAIL => [], JmapCapability::CALENDARS => []],
                    'primaryAccounts' => [JmapCapability::MAIL => 'u1', JmapCapability::CALENDARS => 'u1'],
                    'apiUrl'          => 'https://jmap.example.test/api/',
                    'downloadUrl'     => 'https://jmap.example.test/download/{accountId}/{blobId}/{name}?accept={type}',
                    'uploadUrl'       => 'https://jmap.example.test/upload/{accountId}/',
                    'state'           => 's',
                ]));
            }

            $request   = json_decode((string) $options['body'], true);
            $responses = [];

            foreach ($request['methodCalls'] as [$name, $arguments, $callId]) {
                $this->calls[] = [$name, $arguments];
                $answer        = isset($this->methods[$name]) ? ($this->methods[$name])($arguments) : ['error', ['type' => 'unknownMethod']];

                $responses[] = 'error' === ($answer[0] ?? null) ? ['error', $answer[1], $callId] : [$name, $answer, $callId];
            }

            return new MockResponse(json_encode(['methodResponses' => $responses, 'sessionState' => 's']));
        });

        return new JmapCalendarSyncDriver(new JmapClient($http), new UserTimezoneResolver('Europe/Berlin'));
    }

    private function account(): Account
    {
        $account                   = new Account();
        $account->email            = 'me@example.test';
        $account->username         = 'me@example.test';
        $account->password         = 'secret';
        $account->incomingProtocol = IncomingProtocol::Jmap;
        $account->imapHost         = 'jmap.example.test';
        $account->imapPort         = 443;

        return $account;
    }

    private function calendar(): Calendar
    {
        $calendar           = new Calendar();
        $calendar->account  = $this->account();
        $calendar->remoteId = 'cal1';
        $calendar->timeZone = 'Europe/Berlin';

        return $calendar;
    }
}
