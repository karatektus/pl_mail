<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\Graph;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\CalendarSyncThrottledException;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Mail\Account;
use App\Service\Calendar\Sync\Graph\GraphCalendarSyncDriver;
use App\Service\Calendar\Sync\Graph\GraphEventMapper;
use App\Service\Calendar\Sync\Graph\GraphRecurrenceMapper;
use App\Service\Calendar\Sync\Graph\GraphTimeZoneMapper;
use App\Service\OAuth\OAuthTokenManager;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * What this driver promises the engine, held against the answers Graph actually
 * gives.
 *
 * Three of those promises are the reason the file exists, and each of them fails
 * silently when it is broken:
 *
 *   **One window, ending at a deltaLink.** The driver follows @odata.nextLink
 *   itself. Storing an intermediate `nextLink` as the sync token makes the next
 *   run resume in the middle of a window it has already applied and then treat
 *   that partial page as the whole of it — CalendarPuller prunes on a full read,
 *   so the visible symptom is events disappearing.
 *
 *   **One row per series.** calendarView is an expanded view, so a weekly
 *   meeting arrives as fifty instances. Letting them through writes fifty rows
 *   where the engine wants one and RecurrenceMaterialiser writes the rest.
 *
 *   **The right exception.** A worker has three responses to a failure and the
 *   status alone does not choose between them. A 410 that is not translated
 *   retries the same dead token forever; a 403 that is written off as permanent
 *   dead-letters a calendar that was only briefly refused.
 *
 * Against a MockHttpClient rather than a tenant, for the reason
 * GmailApiClientFailureTest gives: the failures worth covering are status codes
 * nobody can produce on demand from a real account. Every collaborator is final,
 * so they are built rather than doubled — only OAuthTokenManager is stubbed, and
 * only because a token is not a behaviour this file is about.
 */
final class GraphCalendarSyncDriverTest extends TestCase
{
    // ── supports ─────────────────────────────────────────────────────────────

    public function testOnlyAMicrosoftOAuthAccountIsClaimed(): void
    {
        // A driver that claims too broadly silently steals another driver's
        // calendars — the registry takes the first that says yes.
        $driver = $this->driver(new MockHttpClient([]));

        self::assertTrue($driver->supports(CalendarSource::ofAccount($this->account())));
        self::assertFalse($driver->supports(CalendarSource::ofAccount(
            $this->account(MailProvider::Google->value),
        )));

        $imap           = $this->account();
        $imap->authType = AuthType::Password->value;

        self::assertFalse($driver->supports(CalendarSource::ofAccount($imap)), 'an IMAP mailbox has no calendar API');
    }

    // ── discover ─────────────────────────────────────────────────────────────

    public function testDiscoveryMarksACalendarTheAccountCannotWriteToAsReadOnly(): void
    {
        // isReadOnly is what stops the engine ever pushing. Getting it wrong on
        // a colleague's shared calendar means every local edit is sent and
        // refused, once per sweep, forever.
        $http = new MockHttpClient([
            $this->json(['value' => 'W. Europe Standard Time']),
            $this->json(['value' => [
                [
                    'id'                => 'CAL1',
                    'name'              => 'Calendar',
                    'hexColor'          => '#0078D4',
                    'canEdit'           => true,
                    'isDefaultCalendar' => true,
                ],
                [
                    'id'                => 'CAL2',
                    'name'              => "Dave's calendar",
                    'hexColor'          => '',
                    'canEdit'           => false,
                    'isDefaultCalendar' => false,
                ],
            ]]),
        ]);

        $calendars = $this->driver($http)->discover(CalendarSource::ofAccount($this->account()));

        self::assertCount(2, $calendars);

        self::assertSame('CAL1', $calendars[0]->remoteId);
        self::assertSame('Calendar', $calendars[0]->name);
        self::assertSame('#0078d4', $calendars[0]->color);
        self::assertFalse($calendars[0]->isReadOnly);
        self::assertTrue($calendars[0]->isPrimary);

        self::assertTrue($calendars[1]->isReadOnly, 'canEdit false must reach the engine as read-only');
        self::assertFalse($calendars[1]->isPrimary);
    }

    public function testACalendarLeftOnGraphsAutoColourGetsNoColourRatherThanTheWordAuto(): void
    {
        // Graph's `color` is a theme name — auto, lightBlue — and only hexColor
        // holds a colour. Calendar::$color is a seven-character #rrggbb column,
        // so "auto" is both unstorable and a swatch of nothing; null is what
        // makes CalendarProvisioner pick from Calendar::COLORS.
        $http = new MockHttpClient([
            $this->json(['value' => 'W. Europe Standard Time']),
            $this->json(['value' => [['id' => 'CAL1', 'name' => 'Calendar', 'color' => 'auto']]]),
        ]);

        $calendars = $this->driver($http)->discover(CalendarSource::ofAccount($this->account()));

        self::assertNull($calendars[0]->color);
    }

    public function testTheMailboxTimeZoneBecomesTheDefaultForEveryCalendarItHolds(): void
    {
        // Graph has no per-calendar zone at all, so this is the only thing that
        // makes a synced calendar default to the zone the user's colleagues
        // write invitations in.
        $http = new MockHttpClient([
            $this->json(['value' => 'W. Europe Standard Time']),
            $this->json(['value' => [['id' => 'CAL1', 'name' => 'Calendar']]]),
        ]);

        $calendars = $this->driver($http)->discover(CalendarSource::ofAccount($this->account()));

        self::assertSame('Europe/Berlin', $calendars[0]->timeZone);
    }

    public function testAMailboxThatWillNotShareItsTimeZoneStillListsItsCalendars(): void
    {
        // The zone is a nicety. Refusing to list somebody's calendars because a
        // settings read was declined trades the feature for the default.
        $http = new MockHttpClient([
            $this->json(['error' => ['code' => 'ErrorAccessDenied']], 403),
            $this->json(['value' => [['id' => 'CAL1', 'name' => 'Calendar']]]),
        ]);

        $calendars = $this->driver($http)->discover(CalendarSource::ofAccount($this->account()));

        self::assertCount(1, $calendars);
        self::assertNull($calendars[0]->timeZone);
    }

    // ── pull ─────────────────────────────────────────────────────────────────

    public function testAPullCarriesTimedEventsAllDayEventsAndDeletionsInOneWindow(): void
    {
        $http = new MockHttpClient([
            $this->json([
                'value' => [
                    [
                        'id'                    => 'EV1',
                        '@odata.etag'           => 'W/"1"',
                        'iCalUId'               => 'uid-1',
                        'subject'               => 'Standup',
                        'originalStartTimeZone' => 'W. Europe Standard Time',
                        'start'                 => ['dateTime' => '2026-08-04T09:00:00.0000000', 'timeZone' => 'UTC'],
                        'end'                   => ['dateTime' => '2026-08-04T10:00:00.0000000', 'timeZone' => 'UTC'],
                    ],
                    [
                        'id'       => 'EV2',
                        'iCalUId'  => 'uid-2',
                        'subject'  => 'Company holiday',
                        'isAllDay' => true,
                        'start'    => ['dateTime' => '2026-08-10T00:00:00.0000000', 'timeZone' => 'UTC'],
                        'end'      => ['dateTime' => '2026-08-11T00:00:00.0000000', 'timeZone' => 'UTC'],
                    ],
                    ['id' => 'EV3', '@removed' => ['reason' => 'deleted']],
                ],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/calendars/CAL1/calendarView/delta?$deltatoken=B',
            ]),
        ]);

        $changes = $this->driver($http)->pull($this->calendar(), 'https://graph.microsoft.com/delta?$deltatoken=A');

        self::assertCount(3, $changes->events);
        self::assertFalse($changes->requiresFullResync);

        self::assertSame('Europe/Berlin', $changes->events[0]->jscalendar['timeZone'] ?? null);
        self::assertSame('2026-08-04T09:00:00+00:00', $changes->events[0]->startsAt?->format('c'));

        self::assertTrue($changes->events[1]->jscalendar['showWithoutTime'] ?? null);

        self::assertTrue($changes->events[2]->isDeleted, '@removed is a deletion');
        self::assertSame('EV3', $changes->events[2]->remoteId);
        self::assertNull($changes->events[2]->jscalendar);
    }

    public function testTheStoredTokenIsTheDeltaLinkAtTheEndOfEveryPageAndNeverANextLink(): void
    {
        // A nextLink stored as the sync token resumes the next run in the middle
        // of a window already applied, and CalendarPuller then treats that
        // partial page as a complete full read and prunes everything it does not
        // mention.
        $first = $this->json([
            'value'           => [$this->graphEvent('EV1', 'uid-1')],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/calendars/CAL1/calendarView/delta?$skiptoken=PAGE2',
        ]);
        $second = $this->json([
            'value'            => [$this->graphEvent('EV2', 'uid-2')],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/calendars/CAL1/calendarView/delta?$deltatoken=DONE',
        ]);

        $http = new MockHttpClient([$first, $second]);

        $changes = $this->driver($http)->pull($this->calendar(), null);

        self::assertCount(2, $changes->events, 'both pages belong to one window');
        self::assertSame(
            'https://graph.microsoft.com/v1.0/me/calendars/CAL1/calendarView/delta?$deltatoken=DONE',
            $changes->nextSyncToken,
        );
        self::assertSame(2, $http->getRequestsCount());
        self::assertStringContainsString('$skiptoken=PAGE2', $second->getRequestUrl());
    }

    public function testAFirstPullAsksForTheWindowPlMailCanActuallyDraw(): void
    {
        // The window is RecurrenceMaterialiser's horizon. A wider one fetches
        // events that can never be shown; a narrower one leaves holes inside a
        // range the UI scrolls to.
        $response = $this->json(['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/delta?x=1']);

        $this->driver(new MockHttpClient([$response]))->pull($this->calendar(), null);

        $url  = $response->getRequestUrl();
        $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        self::assertStringContainsString('/me/calendars/CAL1/calendarView/delta', $url);
        self::assertStringContainsString('startDateTime=' . rawurlencode($now->modify('-1 year')->format('Y')), $url);
        self::assertStringContainsString('endDateTime=', $url);
    }

    public function testAResumedPullPresentsTheStoredLinkAndNothingElse(): void
    {
        // A deltaLink already carries the window the chain was opened with.
        // Re-sending startDateTime either restarts the enumeration or is
        // ignored, and neither is a thing to do by accident.
        $response = $this->json(['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/delta?x=2']);

        $this->driver(new MockHttpClient([$response]))
            ->pull($this->calendar(), 'https://graph.microsoft.com/delta?$deltatoken=A');

        self::assertSame('https://graph.microsoft.com/delta?$deltatoken=A', $response->getRequestUrl());
    }

    public function testTheExpandedInstancesOfASeriesBecomeTheOneSeriesTheyBelongTo(): void
    {
        // calendarView is an expanded view: a weekly meeting arrives as one
        // entry per week. Written through, that is fifty rows where the engine
        // wants one, fifty UIDs no other client shares, and fifty pushes back at
        // Graph the first time somebody edits "the meeting".
        $http = new MockHttpClient([
            $this->json([
                'value' => [
                    ['id' => 'OCC1', 'type' => 'occurrence', 'seriesMasterId' => 'MASTER'] + $this->times(),
                    ['id' => 'OCC2', 'type' => 'occurrence', 'seriesMasterId' => 'MASTER'] + $this->times(),
                    ['id' => 'OCC3', 'type' => 'exception', 'seriesMasterId' => 'MASTER'] + $this->times(),
                ],
                '@odata.deltaLink' => 'https://graph.microsoft.com/delta?$deltatoken=B',
            ]),
            $this->json([
                'id'         => 'MASTER',
                'iCalUId'    => 'uid-master',
                'subject'    => 'Standup',
                'type'       => 'seriesMaster',
                'recurrence' => [
                    'pattern' => ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => ['tuesday']],
                    'range'   => ['type' => 'noEnd', 'startDate' => '2026-08-04'],
                ],
            ] + $this->times()),
        ]);

        $changes = $this->driver($http)->pull($this->calendar(), 'https://graph.microsoft.com/delta?$deltatoken=A');

        self::assertCount(1, $changes->events, 'three instances are one series');
        self::assertSame('MASTER', $changes->events[0]->remoteId);
        self::assertSame('weekly', $changes->events[0]->jscalendar['recurrenceRules'][0]['frequency'] ?? null);
        self::assertSame(2, $http->getRequestsCount(), 'the master is fetched once, not once per instance');
    }

    public function testAnInstanceSomebodyMovedIsAnOverrideOnTheSeriesRatherThanAMentionOfIt(): void
    {
        // Collapsed onto the series — which is what every exception entry used
        // to be — the instance goes on being drawn at the time it was moved away
        // from, and the afternoon it was moved to is empty.
        $http = new MockHttpClient([
            $this->json([
                'value' => [
                    [
                        'id'             => 'OCC3',
                        'type'           => 'exception',
                        'seriesMasterId' => 'MASTER',
                        'subject'        => 'Standup (moved)',
                        'originalStart'  => '2026-08-11T09:00:00.0000000Z',
                        'start'          => ['dateTime' => '2026-08-11T15:00:00.0000000', 'timeZone' => 'UTC'],
                        'end'            => ['dateTime' => '2026-08-11T16:00:00.0000000', 'timeZone' => 'UTC'],
                    ],
                    ['id' => 'MASTER', 'iCalUId' => 'uid-m', 'type' => 'seriesMaster'] + $this->times(),
                ],
                '@odata.deltaLink' => 'https://graph.microsoft.com/delta?$deltatoken=B',
            ]),
        ]);

        $changes = $this->driver($http)->pull($this->calendar(), 'https://graph.microsoft.com/delta?$deltatoken=A');

        self::assertCount(2, $changes->events, 'the series, and one instance of it');
        self::assertSame(1, $http->getRequestsCount(), 'the master was in the window, so it is not fetched again');

        $instance = $changes->events[0];

        self::assertTrue($instance->isSeriesInstance());
        self::assertSame('MASTER', $instance->seriesRemoteId);

        // originalStart, not start: the start it was dragged to is not a name
        // anything can look the patch up by.
        self::assertSame('2026-08-11T09:00:00+00:00', $instance->recurrenceId?->format('c'));
        self::assertSame('2026-08-11T15:00:00+00:00', $instance->startsAt?->format('c'));
        self::assertSame('PT1H', $instance->jscalendar['duration'] ?? null);

        self::assertFalse($changes->events[1]->isSeriesInstance(), 'the master is not an instance of itself');
    }

    public function testAnExceptionWithNoOriginalStartStaysAMereMentionOfItsSeries(): void
    {
        // There is no key to file the patch under, so filing it would lose the
        // instance rather than move it. The series is still fetched and still
        // correct, which is where this driver was for every exception.
        $http = new MockHttpClient([
            $this->json([
                'value' => [
                    ['id' => 'OCC3', 'type' => 'exception', 'seriesMasterId' => 'MASTER'] + $this->times(),
                ],
                '@odata.deltaLink' => 'https://graph.microsoft.com/delta?$deltatoken=B',
            ]),
            $this->json(['id' => 'MASTER', 'iCalUId' => 'uid-m', 'type' => 'seriesMaster'] + $this->times()),
        ]);

        $changes = $this->driver($http)->pull($this->calendar(), 'https://graph.microsoft.com/delta?$deltatoken=A');

        self::assertCount(1, $changes->events);
        self::assertSame('MASTER', $changes->events[0]->remoteId);
    }

    public function testASeriesTheWindowAlreadyDescribedIsNotFetchedAgain(): void
    {
        $http = new MockHttpClient([
            $this->json([
                'value' => [
                    ['id' => 'MASTER', 'iCalUId' => 'uid-m', 'type' => 'seriesMaster'] + $this->times(),
                    ['id' => 'OCC1', 'type' => 'occurrence', 'seriesMasterId' => 'MASTER'] + $this->times(),
                ],
                '@odata.deltaLink' => 'https://graph.microsoft.com/delta?$deltatoken=B',
            ]),
        ]);

        $changes = $this->driver($http)->pull($this->calendar(), 'https://graph.microsoft.com/delta?$deltatoken=A');

        self::assertCount(1, $changes->events);
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testAFullReadReportsNoTombstonesBecauseThereIsNothingToTombstoneAgainst(): void
    {
        // The engine treats a full read as authoritative and removes local rows
        // the listing did not mention, so a tombstone in one is at best noise
        // and at worst a second delete of a row a later entry recreated.
        $http = new MockHttpClient([
            $this->json([
                'value' => [
                    $this->graphEvent('EV1', 'uid-1'),
                    ['id' => 'EV9', '@removed' => ['reason' => 'deleted']],
                ],
                '@odata.deltaLink' => 'https://graph.microsoft.com/delta?$deltatoken=B',
            ]),
        ]);

        $changes = $this->driver($http)->pull($this->calendar(), null);

        self::assertCount(1, $changes->events);
        self::assertFalse($changes->events[0]->isDeleted);
    }

    // ── Failure classification ───────────────────────────────────────────────

    public function testADeadDeltaTokenAsksForAFullReadRatherThanFailingTheCalendar(): void
    {
        // Graph answers 410 resyncRequired to a token it can no longer resume
        // from, which is a normal outcome of leaving a calendar alone for a
        // week. Anything but this retries the same dead token forever.
        $http = new MockHttpClient([
            $this->json(['error' => ['code' => 'resyncRequired', 'message' => 'Resync is required.']], 410),
        ]);

        $this->expectException(CalendarResyncRequiredException::class);

        $this->driver($http)->pull($this->calendar(), 'https://graph.microsoft.com/delta?$deltatoken=OLD');
    }

    public function testAThrottledPullWaitsExactlyAsLongAsGraphAsked(): void
    {
        // Graph is aggressive about this and per-user-per-minute quotas do not
        // clear inside the ingest transport's one-second backoff.
        $http = new MockHttpClient([
            $this->json(['error' => ['code' => 'TooManyRequests']], 429, ['retry-after' => '90']),
        ]);

        try {
            $this->driver($http)->pull($this->calendar(), null);
            self::fail('a 429 must not look like an empty calendar');
        } catch (CalendarSyncThrottledException $e) {
            self::assertInstanceOf(RecoverableExceptionInterface::class, $e, 'Messenger must retry this');
            self::assertSame(90, $e->getRetryAfterSeconds());
            self::assertSame(90000, $e->getRetryDelay());
            self::assertFalse($e->forceRetry());
        }
    }

    public function testAServiceUnavailableIsAlsoABackoffAndNotAFailure(): void
    {
        $http = new MockHttpClient([$this->json(['error' => ['code' => 'ServiceUnavailable']], 503)]);

        $this->expectException(CalendarSyncThrottledException::class);

        $this->driver($http)->pull($this->calendar(), null);
    }

    public function testAnHttpDateRetryAfterFallsBackToTheMinuteInsteadOfBeingMisparsed(): void
    {
        // (int) "Wed, 21 Oct 2026 …" is 3, which is a retry inside the window
        // that just refused us.
        $http = new MockHttpClient([
            $this->json([], 429, ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT']),
        ]);

        try {
            $this->driver($http)->pull($this->calendar(), null);
            self::fail('a 429 must not look like an empty calendar');
        } catch (CalendarSyncThrottledException $e) {
            self::assertNull($e->getRetryAfterSeconds());
            self::assertSame(60000, $e->getRetryDelay());
        }
    }

    public function testAGrantWithoutCalendarAccessIsNotRetriedAndSaysWhatToDoAboutIt(): void
    {
        // Retrying a missing scope buries the one log line that says "reconnect
        // and allow calendar access" under three identical ones, and the user
        // watches a calendar that never fills in.
        $http = new MockHttpClient([
            $this->json([
                'error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied. Check credentials and try again.'],
            ], 403),
        ]);

        try {
            $this->driver($http)->pull($this->calendar(), null);
            self::fail('a refused grant must not look like an empty calendar');
        } catch (CalendarSyncPermanentException $e) {
            self::assertInstanceOf(UnrecoverableExceptionInterface::class, $e, 'Messenger must not retry this');
            self::assertStringContainsString('Reconnect', $e->getMessage());
            self::assertStringNotContainsString('graph.microsoft.com', $e->getMessage(), 'no URL reaches the user');
        }
    }

    public function testAForbiddenGraphWillNotExplainIsNotWrittenOffAsPermanent(): void
    {
        // A permanent classification is a decision never to try again. Graph
        // answers 403 for a missing scope and for tenant conditions that clear,
        // so a body that does not name an authorization failure raises the base
        // class and lets the transport decide — which is what
        // CalendarSyncPermanentException's own docblock asks for.
        $http = new MockHttpClient([
            $this->json(['error' => ['code' => 'ErrorTooManyObjectsOpened', 'message' => 'The server is busy.']], 403),
        ]);

        try {
            $this->driver($http)->pull($this->calendar(), null);
            self::fail('a 403 must not be swallowed');
        } catch (CalendarSyncException $e) {
            self::assertNotInstanceOf(UnrecoverableExceptionInterface::class, $e);
            self::assertNotInstanceOf(RecoverableExceptionInterface::class, $e);
            self::assertSame(403, $e->getStatus());
        }
    }

    public function testAProxyAnsweringHtmlIsReportedRatherThanCrashingTheSweep(): void
    {
        $http = new MockHttpClient([
            new MockResponse('<html><body>502 Bad Gateway (edge-proxy-7)</body></html>', [
                'http_code'        => 502,
                'response_headers' => ['content-type' => 'text/html'],
            ]),
        ]);

        try {
            $this->driver($http)->pull($this->calendar(), null);
            self::fail('a 502 must not be swallowed');
        } catch (CalendarSyncException $e) {
            self::assertStringContainsString('edge-proxy-7', $e->getMessage());
        }
    }

    // ── push ─────────────────────────────────────────────────────────────────

    public function testCreatingAnEventPostsItIntoTheCalendarAndKeepsWhatGraphAssigned(): void
    {
        // Without the returned id the next pull sees a stranger and writes a
        // second copy of the meeting the user just made.
        $response = $this->json(['id' => 'NEW1', '@odata.etag' => 'W/"7"']);

        $result = $this->driver(new MockHttpClient([$response]))
            ->push($this->calendar(), $this->localEvent());

        self::assertSame('NEW1', $result->remoteId);
        self::assertSame('W/"7"', $result->etag);
        self::assertStringContainsString('/me/calendars/CAL1/events', $response->getRequestUrl());
        self::assertSame('POST', $response->getRequestMethod());
    }

    public function testUpdatingAnEventConditionsTheWriteOnTheEtagPlMailLastSaw(): void
    {
        // Without If-Match the PATCH lands on whatever revision is there now and
        // discards a change made in Outlook between this run's pull and this
        // write, with nothing anywhere to say it happened.
        $response = $this->json(['id' => 'EV1', '@odata.etag' => 'W/"8"']);

        $event             = $this->localEvent();
        $event->remoteId   = 'EV1';
        $event->remoteEtag = 'W/"7"';

        $result = $this->driver(new MockHttpClient([$response]))->push($this->calendar(), $event);

        self::assertSame('PATCH', $response->getRequestMethod());
        self::assertStringContainsString('/me/events/EV1', $response->getRequestUrl());
        self::assertContains('If-Match: W/"7"', $response->getRequestOptions()['headers'] ?? []);
        self::assertSame('W/"8"', $result->etag);
    }

    public function testAnEventPlMailHasNoVersionForIsWrittenUnconditionally(): void
    {
        // There is nothing to condition on, and refusing to write would strand
        // the row: a locally created event that was pushed once with no etag
        // returned can never be edited again.
        $response = $this->json(['id' => 'EV1']);

        $event           = $this->localEvent();
        $event->remoteId = 'EV1';

        $result = $this->driver(new MockHttpClient([$response]))->push($this->calendar(), $event);

        self::assertSame([], array_filter(
            $response->getRequestOptions()['headers'] ?? [],
            static fn (string $header): bool => true === str_starts_with($header, 'If-Match'),
        ));
        self::assertNull($result->etag, 'no etag means the next pull re-reads the event, which is the safe answer');
    }

    public function testAnUpdateGraphRefusesOnTheEtagAsksForAResyncRatherThanOverwriting(): void
    {
        // 412 means the remote moved under us. Retrying the same body would send
        // the identical stale etag; forcing it through would discard whatever
        // the other client wrote.
        $http = new MockHttpClient([
            $this->json(['error' => ['code' => 'ErrorIrresolvableConflict']], 412),
        ]);

        $event             = $this->localEvent();
        $event->remoteId   = 'EV1';
        $event->remoteEtag = 'W/"stale"';

        $this->expectException(CalendarResyncRequiredException::class);

        $this->driver($http)->push($this->calendar(), $event);
    }

    public function testAnEventGraphWillNeverAcceptIsPermanentSoTheRestOfTheBatchGoesOut(): void
    {
        // CalendarPusher swallows exactly this classification and abandons the
        // one row. A 400 raised as anything else fails the whole run, and the
        // retry meets the same event.
        $http = new MockHttpClient([
            $this->json(['error' => ['code' => 'ErrorInvalidRecurrence', 'message' => 'Bad recurrence.']], 400),
        ]);

        $this->expectException(CalendarSyncPermanentException::class);

        $this->driver($http)->push($this->calendar(), $this->localEvent());
    }

    // ── delete ───────────────────────────────────────────────────────────────

    public function testDeletingAnEventThatIsAlreadyGoneIsASuccess(): void
    {
        // Every provider answers 404 to the second delete and the engine retries
        // jobs. Treating it as a failure leaves the row in PendingDelete forever,
        // re-attempting the same delete on every sweep.
        $http = new MockHttpClient([$this->json(['error' => ['code' => 'ErrorItemNotFound']], 404)]);

        $event           = $this->localEvent();
        $event->remoteId = 'EV1';

        $this->driver($http)->delete($this->calendar(), $event);

        $this->expectNotToPerformAssertions();
    }

    public function testAGoneEventIsAlsoASuccessRatherThanADeadToken(): void
    {
        // 410 means resync everywhere else in this driver. On a delete it means
        // the thing being deleted is deleted, which is the outcome asked for.
        $http = new MockHttpClient([$this->json([], 410)]);

        $event           = $this->localEvent();
        $event->remoteId = 'EV1';

        $this->driver($http)->delete($this->calendar(), $event);

        $this->expectNotToPerformAssertions();
    }

    public function testASuccessfulDeleteReadsNoBody(): void
    {
        // Graph answers 204 with nothing in it, and decoding that is an error
        // rather than an empty array.
        $response = new MockResponse('', ['http_code' => 204]);

        $event           = $this->localEvent();
        $event->remoteId = 'EV1';

        $this->driver(new MockHttpClient([$response]))->delete($this->calendar(), $event);

        self::assertSame('DELETE', $response->getRequestMethod());
        self::assertStringContainsString('/me/events/EV1', $response->getRequestUrl());
    }

    public function testARefusedDeleteIsStillAFailure(): void
    {
        // Idempotence covers "already gone", not "not allowed" — swallowing a
        // 403 here removes the local row while the event stays in the calendar.
        $http = new MockHttpClient([
            $this->json(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied.']], 403),
        ]);

        $event           = $this->localEvent();
        $event->remoteId = 'EV1';

        $this->expectException(CalendarSyncPermanentException::class);

        $this->driver($http)->delete($this->calendar(), $event);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function driver(MockHttpClient $http): GraphCalendarSyncDriver
    {
        $tokens = $this->createStub(OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('test-token');

        $zones = new GraphTimeZoneMapper();

        return new GraphCalendarSyncDriver(
            $http,
            $tokens,
            new GraphEventMapper($zones, new GraphRecurrenceMapper()),
            $zones,
            new NullLogger(),
        );
    }

    /**
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     */
    private function json(array $body, int $status = 200, array $headers = []): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code'        => $status,
            'response_headers' => array_merge(['content-type' => 'application/json'], $headers),
        ]);
    }

    private function account(string $provider = MailProvider::Microsoft->value): Account
    {
        $account = new Account();

        $account->authType      = AuthType::OAuth2->value;
        $account->oauthProvider = $provider;

        return $account;
    }

    private function calendar(): Calendar
    {
        $calendar = new Calendar();

        $calendar->account  = $this->account();
        $calendar->remoteId = 'CAL1';

        return $calendar;
    }

    private function localEvent(): CalendarEvent
    {
        $event = new CalendarEvent();

        $event->uid        = 'local-uid';
        $event->title      = 'Standup';
        $event->timeZone   = 'Europe/Berlin';
        $event->startsAt   = new DateTimeImmutable('2026-08-04 09:00:00', new DateTimeZone('UTC'));
        $event->endsAt     = new DateTimeImmutable('2026-08-04 10:00:00', new DateTimeZone('UTC'));
        $event->jscalendar = ['@type' => 'Event'];

        return $event;
    }

    /**
     * @return array<string,mixed>
     */
    private function graphEvent(string $id, string $uid): array
    {
        return ['id' => $id, 'iCalUId' => $uid, 'subject' => 'Something'] + $this->times();
    }

    /**
     * @return array<string,mixed>
     */
    private function times(): array
    {
        return [
            'start' => ['dateTime' => '2026-08-04T09:00:00.0000000', 'timeZone' => 'UTC'],
            'end'   => ['dateTime' => '2026-08-04T10:00:00.0000000', 'timeZone' => 'UTC'],
        ];
    }
}
