<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\Google;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One occurrence of a series, changed here, arriving at Google as that
 * occurrence.
 *
 * The bug this file exists for was silent in the only way that matters: the
 * push succeeded. A user dragged one standup into the afternoon, plMail filed
 * the recurrenceOverride, marked the series for sending, and sent it — as a
 * series update carrying the master's fields and its RRULE, which is a request
 * that says nothing whatever about any single occurrence. Google left every
 * instance where it was, no error was raised anywhere, and the change lived in
 * plMail alone until a later full read carrying any exception for that series
 * replaced the whole override map and took it with it.
 *
 * Google models an instance as a resource of its own under the series, so
 * closing that is two requests: find the instance the rule put at the override's
 * ORIGINAL start, and PATCH it. Four claims follow from that, and each of them
 * is a way the pair can be got wrong:
 *
 *   **The instance is addressed by where the rule put it, never by where it
 *   went.** events.instances filters on `originalStart` for exactly this
 *   reason, and it is the same fact a recurrenceOverride is keyed by. Asking for
 *   the moved time finds nothing, because no instance of the series was ever
 *   scheduled there.
 *
 *   **The key is a LocalDateTime in the series' zone, and the API wants an
 *   instant.** A Berlin key of 10:00 is 08:00Z in August and 09:00Z in January,
 *   so a conversion that reads the key in UTC addresses the right occurrence for
 *   half the year and nothing at all for the other half. That is the case the
 *   data provider below is for, and it is the kind of bug that is reported as
 *   "it stopped working in October".
 *
 *   **Cancelling one instance cancels the instance.** `{"excluded": true}`
 *   becomes a status on the occurrence resource; sent against the series it
 *   would call off the whole meeting.
 *
 *   **A series with nothing to say about its instances sends what it always
 *   sent.** One request, not three — an events.instances call per push of every
 *   recurring event would be quota spent to learn nothing.
 *
 * Against MockHttpClient, because every claim here is about the requests rather
 * than about the answers, and a fixture that only scripted responses could not
 * make one of them.
 */
final class GoogleCalendarInstancePushTest extends TestCase
{
    public function testAMovedInstanceIsPatchedAsItsOwnResourceRatherThanFoldedIntoTheSeries(): void
    {
        $fixture = new GoogleDriverFixture(
            GoogleDriverFixture::json(['id' => 'ev-1', 'etag' => '"9"']),
            GoogleDriverFixture::json(['items' => [['id' => 'ev-1_20260811T080000Z']]]),
            GoogleDriverFixture::json(['id' => 'ev-1_20260811T080000Z']),
        );

        $result = $fixture->driver->push(
            GoogleDriverFixture::calendar(),
            $this->series(['2026-08-11T10:00:00' => [
                '@type'    => 'Event',
                'start'    => '2026-08-11T16:00:00',
                'duration' => 'PT1H',
            ]]),
        );

        // The series' own write is unchanged, and still the one whose id and
        // etag the engine records.
        self::assertSame('PATCH', $fixture->method(0));
        self::assertStringContainsString('/calendars/primary/events/ev-1', $fixture->url(0));
        self::assertSame('"9"', $result->etag);

        self::assertSame('GET', $fixture->method(1));
        self::assertStringContainsString('/calendars/primary/events/ev-1/instances', $fixture->url(1));
        self::assertStringContainsString(
            self::asQueried('2026-08-11T10:00:00+02:00'),
            $fixture->url(1),
            'the instance is named by where the rule put it, which is the only name it keeps',
        );

        self::assertSame('PATCH', $fixture->method(2));
        self::assertStringContainsString('/calendars/primary/events/ev-1_20260811T080000Z', $fixture->url(2));

        $payload = $fixture->payload(2);

        self::assertSame(['dateTime' => '2026-08-11T16:00:00+02:00', 'timeZone' => 'Europe/Berlin'], $payload['start']);
        self::assertSame(['dateTime' => '2026-08-11T17:00:00+02:00', 'timeZone' => 'Europe/Berlin'], $payload['end']);
        self::assertSame('Standup', $payload['summary'], 'an instance nobody renamed carries the series\' own name');
        self::assertArrayNotHasKey('status', $payload, 'confirmed would resurrect an occurrence Google had cancelled');
    }

    public function testARenamedInstanceCarriesItsOwnNameAndNotTheSeriesOne(): void
    {
        $fixture = new GoogleDriverFixture(
            GoogleDriverFixture::json(['id' => 'ev-1']),
            GoogleDriverFixture::json(['items' => [['id' => 'inst-1']]]),
            GoogleDriverFixture::json(['id' => 'inst-1']),
        );

        $fixture->driver->push(
            GoogleDriverFixture::calendar(),
            $this->series(['2026-08-11T10:00:00' => [
                '@type' => 'Event',
                'start' => '2026-08-11T10:00:00',
                'title' => 'Retro',
            ]]),
        );

        self::assertSame('Retro', $fixture->payload(2)['summary'] ?? null);

        // The patch states no duration, so the instance keeps the series' half
        // hour rather than becoming zero-length — an instance that is only
        // renamed is still an instance that has to be somewhere.
        self::assertSame(
            ['dateTime' => '2026-08-11T10:30:00+02:00', 'timeZone' => 'Europe/Berlin'],
            $fixture->payload(2)['end'] ?? null,
        );
    }

    public function testAnExcludedInstanceIsCancelledOnTheInstanceRatherThanOnTheSeries(): void
    {
        // Sent against the series this would call the whole meeting off, which
        // is the worst possible reading of "the user removed one occurrence".
        $fixture = new GoogleDriverFixture(
            GoogleDriverFixture::json(['id' => 'ev-1']),
            GoogleDriverFixture::json(['items' => [['id' => 'ev-1_20260811T080000Z']]]),
            GoogleDriverFixture::json(['id' => 'ev-1_20260811T080000Z']),
        );

        $fixture->driver->push(
            GoogleDriverFixture::calendar(),
            $this->series(['2026-08-11T10:00:00' => ['excluded' => true]]),
        );

        self::assertSame(['status' => 'cancelled'], $fixture->payload(2));
        self::assertStringContainsString('/events/ev-1_20260811T080000Z', $fixture->url(2));
        self::assertStringNotContainsString('/events/ev-1?', $fixture->url(2));

        // showDeleted, so a cancellation pushed twice still finds the instance
        // and reports nothing — a retried job must not log a series that has no
        // instance where one plainly does.
        self::assertStringContainsString('showDeleted=true', $fixture->url(1));
    }

    /**
     * The whole point of resolving the key in the series' own zone: the same
     * wall-clock 10:00 is a different instant in summer and in winter, and
     * Google is asked for an instant.
     */
    #[DataProvider('sidesOfTheClockChange')]
    public function testTheOverrideKeyIsResolvedInTheSeriesZoneAndNotInUtc(string $key, string $expected): void
    {
        $fixture = new GoogleDriverFixture(
            GoogleDriverFixture::json(['id' => 'ev-1']),
            GoogleDriverFixture::json(['items' => [['id' => 'inst-1']]]),
            GoogleDriverFixture::json(['id' => 'inst-1']),
        );

        $fixture->driver->push(
            GoogleDriverFixture::calendar(),
            $this->series([$key => ['@type' => 'Event', 'start' => $key]]),
        );

        self::assertStringContainsString(self::asQueried($expected), $fixture->url(1));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sidesOfTheClockChange(): iterable
    {
        yield 'summer time' => ['2026-08-11T10:00:00', '2026-08-11T10:00:00+02:00'];
        yield 'winter time' => ['2027-01-05T10:00:00', '2027-01-05T10:00:00+01:00'];
    }

    /**
     * An RFC 3339 timestamp as it appears in a query string.
     *
     * Only the plus is escaped — the transport leaves a colon alone, and it is
     * legal there — so rawurlencode() would produce a string this URL never
     * contains and an assertion that could not pass however right the driver
     * was.
     */
    private static function asQueried(string $timestamp): string
    {
        return 'originalStart=' . str_replace('+', '%2B', $timestamp);
    }

    public function testASeriesWithNoOverridesSendsExactlyWhatItSentBefore(): void
    {
        // One request. An events.instances call on every push of every recurring
        // event would be a round trip per sweep to learn that there is nothing
        // to say.
        $fixture = new GoogleDriverFixture(
            GoogleDriverFixture::json(['id' => 'ev-1', 'etag' => '"9"']),
            GoogleDriverFixture::json(['items' => [['id' => 'never-asked-for']]]),
        );

        $fixture->driver->push(GoogleDriverFixture::calendar(), $this->series([]));

        self::assertSame(1, $fixture->http->getRequestsCount());
    }

    public function testAnEventThatNoLongerRepeatsSendsNothingAboutTheInstancesItUsedToHave(): void
    {
        // The override map survives a series being demoted to a single event —
        // CalendarEventWriter keeps it through every edit — and nothing local
        // reads it again, because the expander only consults it while expanding
        // a rule. Sent anyway, each entry would ask Google to change an
        // occurrence that exists neither there nor on this calendar.
        $fixture = new GoogleDriverFixture(
            GoogleDriverFixture::json(['id' => 'ev-1']),
            GoogleDriverFixture::json(['items' => [['id' => 'never-asked-for']]]),
        );

        $event              = $this->series(['2026-08-11T10:00:00' => ['excluded' => true]]);
        $event->isRecurring = false;

        unset($event->jscalendar['recurrenceRules']);

        $fixture->driver->push(GoogleDriverFixture::calendar(), $event);

        self::assertSame(1, $fixture->http->getRequestsCount());
    }

    public function testAnOverrideTheSeriesHasNoInstanceForIsReportedRatherThanInvented(): void
    {
        // An override whose key the rule no longer puts an instance at is what a
        // series edited after one of its occurrences was moved leaves behind.
        // Creating a resource for it would add an occurrence the rule does not
        // have, on a day the user never picked.
        $fixture = new GoogleDriverFixture(
            GoogleDriverFixture::json(['id' => 'ev-1']),
            GoogleDriverFixture::json(['items' => []]),
            GoogleDriverFixture::json(['id' => 'never-asked-for']),
        );

        $fixture->driver->push(
            GoogleDriverFixture::calendar(),
            $this->series(['2026-08-11T10:00:00' => ['@type' => 'Event', 'start' => '2026-08-11T16:00:00']]),
        );

        self::assertSame(2, $fixture->http->getRequestsCount(), 'nothing is written where there is nothing to write');
        self::assertCount(
            1,
            $fixture->logger->matching('info', 'no instance of this series starts where the override says'),
        );
    }

    public function testAnInstanceGoogleRefusesDoesNotCostTheSeriesTheIdItJustReceived(): void
    {
        // The create has already happened by the time an instance is written, so
        // a throw here would stop CalendarPusher storing the id — and the next
        // sweep would create a second copy of the whole meeting. One instance
        // that cannot be placed is worth a log line, not that.
        $fixture = new GoogleDriverFixture(
            GoogleDriverFixture::json(['id' => 'ev-new', 'etag' => '"1"']),
            GoogleDriverFixture::error(404, 'notFound', 'Not Found'),
        );

        $event           = $this->series(['2026-08-11T10:00:00' => ['excluded' => true]]);
        $event->remoteId = null;

        $result = $fixture->driver->push(GoogleDriverFixture::calendar(), $event);

        self::assertSame('POST', $fixture->method(0), 'a row with no remote id is still a create');
        self::assertSame('ev-new', $result->remoteId);
        self::assertCount(1, $fixture->logger->matching('warning', 'one instance of a series could not be written'));
    }

    public function testTheInstancesOfANewlyCreatedSeriesAreWrittenUnderTheIdGoogleJustAssigned(): void
    {
        // A series created and edited before the first push has both to send,
        // and the second write can only be addressed once the first has
        // answered — the local row's remoteId is still null at that point.
        $fixture = new GoogleDriverFixture(
            GoogleDriverFixture::json(['id' => 'ev-new']),
            GoogleDriverFixture::json(['items' => [['id' => 'inst-1']]]),
            GoogleDriverFixture::json(['id' => 'inst-1']),
        );

        $event           = $this->series(['2026-08-11T10:00:00' => ['excluded' => true]]);
        $event->remoteId = null;

        $fixture->driver->push(GoogleDriverFixture::calendar(), $event);

        self::assertStringContainsString('/calendars/primary/events/ev-new/instances', $fixture->url(1));
    }

    /**
     * A weekly Berlin standup with whatever overrides the case needs.
     *
     * @param array<string,array<string,mixed>> $overrides
     */
    private function series(array $overrides): \App\Entity\Calendar\CalendarEvent
    {
        $jscalendar = [
            '@type'           => 'Event',
            'title'           => 'Standup',
            'start'           => '2026-08-04T10:00:00',
            'timeZone'        => 'Europe/Berlin',
            'duration'        => 'PT30M',
            'recurrenceRules' => [['@type' => 'RecurrenceRule', 'frequency' => 'weekly']],
        ];

        if ([] !== $overrides) {
            $jscalendar['recurrenceOverrides'] = $overrides;
        }

        $event = GoogleDriverFixture::event(remoteId: 'ev-1', etag: '"8"', jscalendar: $jscalendar);

        $event->startsAt    = new DateTimeImmutable('2026-08-04 08:00:00', new DateTimeZone('UTC'));
        $event->endsAt      = new DateTimeImmutable('2026-08-04 08:30:00', new DateTimeZone('UTC'));
        $event->isRecurring = true;

        return $event;
    }
}
