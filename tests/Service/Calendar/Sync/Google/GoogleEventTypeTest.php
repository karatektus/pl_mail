<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\Google;

use App\Domain\Enum\Calendar\EventPrivacy;
use App\Service\Calendar\Sync\Google\GoogleEventMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The events Google makes itself, and what a write to one may say (#622).
 *
 * Google restricts updates by `eventType` and refuses the whole PATCH over one
 * field it will not take. A birthday from a contact, edited here, went out with
 * `visibility: default`, a description, a location and the date, and came back
 * 400 eventTypeRestriction — every time, because nothing about the next attempt
 * was different. So the type is kept on the row when it is read, and the write
 * body is cut down to what that type accepts.
 */
final class GoogleEventTypeTest extends TestCase
{
    public function testABirthdayIsReadAsPrivateAndKeepsItsType(): void
    {
        // Older birthdays come back with visibility `default`. Left public here,
        // the first edit would send a visibility Google refuses.
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [[
                'id'         => 'bday-1',
                'iCalUID'    => 'bday-1@google.com',
                'status'     => 'confirmed',
                'summary'    => 'Alice\'s birthday',
                'eventType'  => 'birthday',
                'visibility' => 'default',
                'start'      => ['date' => '2026-03-14'],
                'end'        => ['date' => '2026-03-15'],
                'recurrence' => ['RRULE:FREQ=YEARLY'],
            ]],
            'nextSyncToken' => 'token-1',
        ]));

        $event = $fixture->driver->pull(GoogleDriverFixture::calendar(), null)->events[0];

        self::assertSame('birthday', $event->jscalendar[GoogleEventMapper::EVENT_TYPE] ?? null);
        self::assertSame('private', $event->jscalendar['privacy'] ?? null);
    }

    public function testAnOrdinaryEventRecordsNoType(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json([
            'items' => [[
                'id'        => 'ev-1',
                'status'    => 'confirmed',
                'summary'   => 'Standup',
                'eventType' => 'default',
                'start'     => ['dateTime' => '2026-08-04T10:00:00+02:00'],
                'end'       => ['dateTime' => '2026-08-04T10:30:00+02:00'],
            ]],
        ]));

        $event = $fixture->driver->pull(GoogleDriverFixture::calendar(), null)->events[0];

        self::assertArrayNotHasKey(GoogleEventMapper::EVENT_TYPE, $event->jscalendar ?? []);
        self::assertArrayNotHasKey('privacy', $event->jscalendar ?? [], '`default` is about the calendar, not the event');
    }

    public function testABirthdayEditSendsOnlyItsTitleRemindersAndPrivate(): void
    {
        $event          = GoogleDriverFixture::event(remoteId: 'bday-1', etag: '"4"', jscalendar: [
            '@type'                       => 'Event',
            'description'                 => 'Bring cake',
            'recurrenceRules'             => [['@type' => 'RecurrenceRule', 'frequency' => 'yearly']],
            'alerts'                      => ['1' => [
                '@type'   => 'Alert',
                'action'  => 'display',
                'trigger' => ['@type' => 'OffsetTrigger', 'offset' => '-PT1H'],
            ]],
            GoogleEventMapper::EVENT_TYPE => 'birthday',
        ], isAllDay: true);
        $event->title   = 'Alice turns 40';
        $event->privacy = EventPrivacy::Public;

        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json(['id' => 'bday-1', 'etag' => '"5"']));

        $fixture->driver->push(GoogleDriverFixture::calendar(), $event);

        $payload = $fixture->payload(0);

        self::assertSame(['reminders', 'summary', 'visibility'], self::sortedKeys($payload));
        self::assertSame('Alice turns 40', $payload['summary']);
        self::assertSame('private', $payload['visibility'], 'Google refuses anything else, whatever the row says');
    }

    public function testAGmailEventEditSendsOnlyWhatGoogleLetsAnyoneChange(): void
    {
        $event          = GoogleDriverFixture::event(remoteId: 'flight-1', jscalendar: [
            GoogleEventMapper::EVENT_TYPE => 'fromGmail',
        ]);
        $event->privacy = EventPrivacy::Private;

        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json(['id' => 'flight-1']));

        $fixture->driver->push(GoogleDriverFixture::calendar(), $event);

        self::assertSame(['visibility' => 'private'], $fixture->payload(0));
    }

    #[DataProvider('statusEvents')]
    public function testAStatusEventKeepsItsOwnVisibility(string $eventType): void
    {
        // Working location must stay public, focus time and out of office have
        // their own settings in Google's interface. Not naming it in a PATCH
        // leaves it as Google has it.
        $event = GoogleDriverFixture::event(remoteId: 'ev-1', jscalendar: [GoogleEventMapper::EVENT_TYPE => $eventType]);

        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json(['id' => 'ev-1']));

        $fixture->driver->push(GoogleDriverFixture::calendar(), $event);

        $payload = $fixture->payload(0);

        self::assertArrayNotHasKey('visibility', $payload);
        self::assertSame('Standup', $payload['summary'] ?? null, 'the rest of the body is the ordinary one');
        self::assertArrayHasKey('start', $payload);
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function statusEvents(): iterable
    {
        yield 'focus time'       => ['focusTime'];
        yield 'out of office'    => ['outOfOffice'];
        yield 'working location' => ['workingLocation'];
    }

    public function testAnOrdinaryEventStillSendsItsVisibility(): void
    {
        $fixture = new GoogleDriverFixture(GoogleDriverFixture::json(['id' => 'ev-1']));

        $fixture->driver->push(GoogleDriverFixture::calendar(), GoogleDriverFixture::event(remoteId: 'ev-1'));

        self::assertArrayHasKey('visibility', $fixture->payload(0));
        self::assertArrayHasKey('description', $fixture->payload(0));
    }

    public function testAMovedBirthdayOccurrenceIsNotSentAsAnInstanceWrite(): void
    {
        // Answers are queued for the instance lookup and write too, so the
        // count below is what proves they were not asked for: a refused
        // instance write is only a log line, and would pass unnoticed.
        $event = GoogleDriverFixture::event(remoteId: 'bday-1', jscalendar: [
            '@type'                       => 'Event',
            'start'                       => '2026-03-14T00:00:00',
            'recurrenceRules'             => [['@type' => 'RecurrenceRule', 'frequency' => 'yearly']],
            'recurrenceOverrides'         => ['2027-03-14T00:00:00' => ['start' => '2027-03-15T00:00:00']],
            GoogleEventMapper::EVENT_TYPE => 'birthday',
        ], isAllDay: true);
        $event->isRecurring = true;

        $fixture = new GoogleDriverFixture(
            GoogleDriverFixture::json(['id' => 'bday-1']),
            GoogleDriverFixture::json(['items' => [['id' => 'bday-1_20270314']]]),
            GoogleDriverFixture::json(['id' => 'bday-1_20270314']),
        );

        $result = $fixture->driver->push(GoogleDriverFixture::calendar(), $event);

        self::assertSame('bday-1', $result->remoteId);
        self::assertSame('PATCH', $fixture->method(0));
        self::assertSame(1, $fixture->http->getRequestsCount(), 'the series\' own PATCH and nothing else');
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return list<string>
     */
    private static function sortedKeys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys;
    }
}
