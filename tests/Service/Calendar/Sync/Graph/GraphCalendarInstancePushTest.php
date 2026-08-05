<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\Graph;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Mail\Account;
use App\Service\Calendar\Alert\AlertReader;
use App\Service\Calendar\Sync\Graph\GraphCalendarSyncDriver;
use App\Service\Calendar\Sync\Graph\GraphEventMapper;
use App\Service\Calendar\Sync\Graph\GraphRecurrenceMapper;
use App\Service\Calendar\Sync\Graph\GraphTimeZoneMapper;
use App\Service\OAuth\OAuthTokenManager;
use App\Tests\Service\Calendar\RecordingLogger;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * One occurrence of a series, changed here, arriving at Graph as that
 * occurrence.
 *
 * The same silent failure the Google file describes, with a different shape to
 * the fix. A per-instance edit was pushed as a PATCH of the series, whose
 * `recurrence` is the *pattern* — there is nowhere in it to say that the eleventh
 * of August is in the afternoon this once — so Outlook left every occurrence
 * where it was and nothing reported a problem.
 *
 * What makes Graph harder than Google is that it has no way to ask for the
 * instance at a given original start. `/instances` takes a window over where
 * occurrences *are*, not where the rule put them, so an occurrence already
 * dragged a fortnight away cannot be found by looking near its pattern time at
 * all. Hence two routes, and both are claims here:
 *
 *   **The id a pull recorded is used first.** CalendarEvent::$remoteInstances is
 *   the record of which occurrence each of Graph's instance ids is, written by
 *   the pull that saw it, and it addresses any occurrence however far it has
 *   been moved — with no listing at all.
 *
 *   **A window around the original start is the fallback, and enough for what it
 *   serves.** An occurrence nothing has moved yet is at its original start by
 *   definition, which is the case a first push meets.
 *
 *   **A recorded id Graph has re-keyed is not a failure.** Graph re-keys an
 *   occurrence for some edits, so a 404 on the direct write means "ask again",
 *   not "give up on this instance".
 *
 *   **Cancelling one occurrence deletes that occurrence.** Graph's `isCancelled`
 *   is read-only and its cancel action mails every attendee, which is the same
 *   restriction that makes cancelling a whole event a delete here. Sent against
 *   the series it would delete the meeting.
 */
final class GraphCalendarInstancePushTest extends TestCase
{
    public function testTheInstanceIdAPullRecordedIsWrittenToDirectly(): void
    {
        // No listing at all: the id is the answer to a question Graph cannot be
        // asked, and asking it a different way would be a round trip that
        // cannot reach an instance somebody has already dragged.
        $series   = $this->json(['id' => 'MASTER', '@odata.etag' => 'W/"9"']);
        $instance = $this->json(['id' => 'OCC-AUG11']);
        $http     = new MockHttpClient([$series, $instance]);

        $event = $this->series(['2026-08-11T10:00:00' => [
            '@type'    => 'Event',
            'start'    => '2026-08-11T16:00:00',
            'duration' => 'PT1H',
        ]]);

        $event->remoteInstances = ['OCC-AUG11' => '2026-08-11T08:00:00Z'];

        $result = $this->driver($http)->push($this->calendar(), $event);

        self::assertSame(2, $http->getRequestsCount());
        self::assertSame('W/"9"', $result->etag, 'the series\' own write is still what the engine records');

        self::assertSame('PATCH', $instance->getRequestMethod());
        self::assertStringContainsString('/me/events/OCC-AUG11', $instance->getRequestUrl());

        $payload = $this->payload($instance);

        self::assertSame(['dateTime' => '2026-08-11T16:00:00', 'timeZone' => 'W. Europe Standard Time'], $payload['start']);
        self::assertSame(['dateTime' => '2026-08-11T17:00:00', 'timeZone' => 'W. Europe Standard Time'], $payload['end']);
        self::assertSame('Standup', $payload['subject']);
    }

    public function testTheRecordedIdIsMatchedOnTheOriginalStartAndNotOnAnyOtherOccurrence(): void
    {
        // The map holds one entry per occurrence Graph has named — a weekly
        // series builds up dozens — and picking the wrong one moves a meeting
        // the user never touched.
        $series   = $this->json(['id' => 'MASTER']);
        $instance = $this->json(['id' => 'OCC-AUG18']);
        $http     = new MockHttpClient([$series, $instance]);

        $event = $this->series(['2026-08-18T10:00:00' => ['@type' => 'Event', 'start' => '2026-08-18T16:00:00']]);

        $event->remoteInstances = [
            'OCC-AUG04' => '2026-08-04T08:00:00Z',
            'OCC-AUG11' => '2026-08-11T08:00:00Z',
            'OCC-AUG18' => '2026-08-18T08:00:00Z',
        ];

        $this->driver($http)->push($this->calendar(), $event);

        self::assertStringContainsString('/me/events/OCC-AUG18', $instance->getRequestUrl());
    }

    public function testAnInstanceNoPullHasNamedIsFoundByAskingTheSeriesAboutThatDay(): void
    {
        $series   = $this->json(['id' => 'MASTER']);
        $listing  = $this->json(['value' => [
            ['id' => 'OCC-AUG11', 'originalStart' => '2026-08-11T08:00:00.0000000Z'] + $this->times(),
        ]]);
        $instance = $this->json(['id' => 'OCC-AUG11']);

        $http = new MockHttpClient([$series, $listing, $instance]);

        $this->driver($http)->push($this->calendar(), $this->series([
            '2026-08-11T10:00:00' => ['@type' => 'Event', 'start' => '2026-08-11T16:00:00'],
        ]));

        self::assertStringContainsString('/me/events/MASTER/instances', $listing->getRequestUrl());

        // A day either side of the ORIGINAL start, in UTC. The Berlin key reads
        // 10:00 and the instant is 08:00Z, so a window computed from the key's
        // digits would be an hour or two out and — on the wrong side of a clock
        // change — miss the occurrence entirely.
        self::assertStringContainsString('startDateTime=2026-08-10T08:00:00Z', $listing->getRequestUrl());
        self::assertStringContainsString('endDateTime=2026-08-12T08:00:00Z', $listing->getRequestUrl());

        self::assertSame('PATCH', $instance->getRequestMethod());
        self::assertStringContainsString('/me/events/OCC-AUG11', $instance->getRequestUrl());
    }

    public function testAnOccurrenceListedAtAnotherStartIsNotTheOneTheOverrideNames(): void
    {
        // The window is a day wide because Graph demands one, so it answers with
        // the neighbours too. Taking the first would move whichever occurrence
        // Graph happened to list first.
        $series  = $this->json(['id' => 'MASTER']);
        $listing = $this->json(['value' => [
            ['id' => 'OCC-AUG10', 'originalStart' => '2026-08-10T08:00:00.0000000Z'] + $this->times(),
            ['id' => 'OCC-AUG11', 'originalStart' => '2026-08-11T08:00:00.0000000Z'] + $this->times(),
        ]]);
        $instance = $this->json(['id' => 'OCC-AUG11']);

        $http = new MockHttpClient([$series, $listing, $instance]);

        $this->driver($http)->push($this->calendar(), $this->series([
            '2026-08-11T10:00:00' => ['@type' => 'Event', 'start' => '2026-08-11T16:00:00'],
        ]));

        self::assertStringContainsString('/me/events/OCC-AUG11', $instance->getRequestUrl());
    }

    public function testARecordedIdGraphHasRekeyedSendsThePushLookingRatherThanGivingUp(): void
    {
        // Graph re-keys an occurrence for some edits, so an id learned last week
        // can name nothing today. Treated as a permanent refusal, the instance
        // sitting there under its new id would never be written again.
        $series  = $this->json(['id' => 'MASTER']);
        $stale   = $this->json(['error' => ['code' => 'ErrorItemNotFound']], 404);
        $listing = $this->json(['value' => [
            ['id' => 'OCC-NEW', 'originalStart' => '2026-08-11T08:00:00.0000000Z'] + $this->times(),
        ]]);
        $written = $this->json(['id' => 'OCC-NEW']);

        $http = new MockHttpClient([$series, $stale, $listing, $written]);

        $event = $this->series(['2026-08-11T10:00:00' => ['@type' => 'Event', 'start' => '2026-08-11T16:00:00']]);

        $event->remoteInstances = ['OCC-OLD' => '2026-08-11T08:00:00Z'];

        $this->driver($http)->push($this->calendar(), $event);

        self::assertStringContainsString('/me/events/OCC-OLD', $stale->getRequestUrl());
        self::assertStringContainsString('/me/events/OCC-NEW', $written->getRequestUrl());
    }

    public function testAnExcludedInstanceDeletesTheOccurrenceAndNotTheSeries(): void
    {
        // isCancelled is read-only at Graph and its cancel action mails every
        // attendee, so a deletion of the occurrence is what "this one is off"
        // means here. Sent against MASTER it would delete the meeting.
        $series   = $this->json(['id' => 'MASTER']);
        $deleted  = new MockResponse('', ['http_code' => 204]);
        $http     = new MockHttpClient([$series, $deleted]);

        $event = $this->series(['2026-08-11T10:00:00' => ['excluded' => true]]);

        $event->remoteInstances = ['OCC-AUG11' => '2026-08-11T08:00:00Z'];

        $this->driver($http)->push($this->calendar(), $event);

        self::assertSame('DELETE', $deleted->getRequestMethod());
        self::assertStringContainsString('/me/events/OCC-AUG11', $deleted->getRequestUrl());
        self::assertStringNotContainsString('/me/events/MASTER', $deleted->getRequestUrl());
    }

    public function testASeriesWithNoOverridesSendsExactlyWhatItSentBefore(): void
    {
        // One request. Looking for instances on every push of every recurring
        // event would be a listing per sweep to learn there is nothing to say.
        $http = new MockHttpClient([
            $this->json(['id' => 'MASTER', '@odata.etag' => 'W/"9"']),
            $this->json(['value' => []]),
        ]);

        $this->driver($http)->push($this->calendar(), $this->series([]));

        self::assertSame(1, $http->getRequestsCount());
    }

    public function testAnOverrideTheSeriesHasNoOccurrenceForIsReportedRatherThanInvented(): void
    {
        $logger = new RecordingLogger();
        $http   = new MockHttpClient([
            $this->json(['id' => 'MASTER']),
            $this->json(['value' => []]),
        ]);

        $this->driver($http, $logger)->push($this->calendar(), $this->series([
            '2026-08-11T10:00:00' => ['@type' => 'Event', 'start' => '2026-08-11T16:00:00'],
        ]));

        self::assertSame(2, $http->getRequestsCount());
        self::assertCount(
            1,
            $logger->matching('info', 'no instance of this series starts where the override says'),
        );
    }

    public function testAnInstanceGraphRefusesDoesNotCostTheSeriesTheIdItJustReceived(): void
    {
        // The create has already happened, so throwing would stop CalendarPusher
        // recording the id and the next sweep would make a second copy of the
        // whole meeting.
        $logger = new RecordingLogger();
        $http   = new MockHttpClient([
            $this->json(['id' => 'NEW1', '@odata.etag' => 'W/"1"']),
            $this->json(['error' => ['code' => 'ErrorInvalidRecurrence', 'message' => 'No.']], 400),
        ]);

        $event           = $this->series(['2026-08-11T10:00:00' => ['excluded' => true]]);
        $event->remoteId = null;

        $event->remoteInstances = ['OCC-AUG11' => '2026-08-11T08:00:00Z'];

        $result = $this->driver($http, $logger)->push($this->calendar(), $event);

        self::assertSame('NEW1', $result->remoteId);
        self::assertCount(1, $logger->matching('warning', 'one instance of a series could not be written'));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function driver(MockHttpClient $http, ?RecordingLogger $logger = null): GraphCalendarSyncDriver
    {
        $tokens = $this->createStub(OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('test-token');

        $zones = new GraphTimeZoneMapper();

        return new GraphCalendarSyncDriver(
            $http,
            $tokens,
            new GraphEventMapper($zones, new GraphRecurrenceMapper(), new AlertReader(new NullLogger())),
            $zones,
            $logger ?? new RecordingLogger(),
        );
    }

    /**
     * @param array<string,mixed> $body
     */
    private function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code'        => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(MockResponse $response): array
    {
        $body    = $response->getRequestOptions()['body'] ?? '';
        $decoded = json_decode(true === is_string($body) ? $body : '', true);

        return true === is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function times(): array
    {
        return [
            'start' => ['dateTime' => '2026-08-11T08:00:00.0000000', 'timeZone' => 'UTC'],
            'end'   => ['dateTime' => '2026-08-11T08:30:00.0000000', 'timeZone' => 'UTC'],
        ];
    }

    private function account(): Account
    {
        $account = new Account();

        $account->authType      = AuthType::OAuth2->value;
        $account->oauthProvider = MailProvider::Microsoft->value;

        return $account;
    }

    private function calendar(): Calendar
    {
        $calendar = new Calendar();

        $calendar->account  = $this->account();
        $calendar->remoteId = 'CAL1';

        return $calendar;
    }

    /**
     * A weekly Berlin standup with whatever overrides the case needs.
     *
     * @param array<string,array<string,mixed>> $overrides
     */
    private function series(array $overrides): CalendarEvent
    {
        $event = new CalendarEvent();

        $event->uid         = 'series-uid';
        $event->title       = 'Standup';
        $event->timeZone    = 'Europe/Berlin';
        $event->startsAt    = new DateTimeImmutable('2026-08-04 08:00:00', new DateTimeZone('UTC'));
        $event->endsAt      = new DateTimeImmutable('2026-08-04 08:30:00', new DateTimeZone('UTC'));
        $event->isRecurring = true;
        $event->remoteId    = 'MASTER';
        $event->remoteEtag  = 'W/"8"';
        $event->jscalendar  = [
            '@type'           => 'Event',
            'title'           => 'Standup',
            'start'           => '2026-08-04T10:00:00',
            'timeZone'        => 'Europe/Berlin',
            'duration'        => 'PT30M',
            'recurrenceRules' => [['@type' => 'RecurrenceRule', 'frequency' => 'weekly']],
        ];

        if ([] !== $overrides) {
            $event->jscalendar['recurrenceOverrides'] = $overrides;
        }

        return $event;
    }
}
