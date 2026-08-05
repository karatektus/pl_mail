<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\CalDav;

use App\Service\Calendar\Alert\AlertReader;
use App\Service\Calendar\RecurrenceRuleConverter;
use App\Service\Calendar\Sync\CalDav\CalDavEventConverter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The .ics on a CalDAV resource, and the JSCalendar the engine stores.
 *
 * The claim these exist for: **a participant's roles accumulate on their
 * address, they do not overwrite it.** One person is routinely both ORGANIZER
 * and ATTENDEE — it is what Google Calendar sends and what RFC 5545 expects —
 * and the participant map is keyed by address and written in property order, so
 * a mapping that assigns rather than merges loses `owner` on the second pass.
 * The event then has no organiser at all, and the invite card has nobody to
 * answer, so it offers no answer. That bug was found and fixed in
 * IcsEventExtractor; this mapping is where it would come back, because it maps
 * the same VEVENT for a different caller.
 *
 * Everything else here is a round-trip claim. The same meeting arrives twice —
 * as an invitation in the mailbox and as a resource on the connected calendar —
 * and CalendarPuller matches the two by UID, so two mappings that disagreed
 * about all-day or duration would present as an event that changes shape
 * depending on which sync ran last.
 */
final class CalDavEventConverterTest extends TestCase
{
    public function testAnOrganiserWhoIsAlsoAnAttendeeKeepsBothRoles(): void
    {
        $ics = $this->calendar(<<<'ICS'
            ORGANIZER;CN=Alice Smith:mailto:alice@example.com
            ATTENDEE;CN=Alice Smith;PARTSTAT=ACCEPTED:mailto:alice@example.com
            ATTENDEE;CN=Bob Jones;PARTSTAT=NEEDS-ACTION:mailto:bob@example.com
            ICS);

        $event = $this->converter()->toRemoteEvent($ics, '/c/1.ics', '"e"');

        self::assertNotNull($event);
        $participants = $event->jscalendar['participants'] ?? [];

        self::assertCount(2, $participants, 'one person mentioned twice is one participant');
        self::assertSame(
            ['owner' => true, 'attendee' => true],
            $participants['alice@example.com']['roles'] ?? null,
            'the organiser who is also going must keep owner',
        );
        self::assertSame(['attendee' => true], $participants['bob@example.com']['roles'] ?? null);
    }

    public function testTheOrganisersOwnAnswerComesFromTheirAttendeeLine(): void
    {
        // An ORGANIZER line carries no PARTSTAT, so the only place the
        // organiser's own answer exists is the attendee line for the same
        // person — which is the line a mapping that overwrites would have
        // thrown away along with the role.
        $ics = $this->calendar(<<<'ICS'
            ORGANIZER;CN=Alice Smith:mailto:alice@example.com
            ATTENDEE;CN=Alice Smith;PARTSTAT=ACCEPTED:mailto:alice@example.com
            ICS);

        $event = $this->converter()->toRemoteEvent($ics, '/c/1.ics', null);

        self::assertSame('accepted', $event?->jscalendar['participants']['alice@example.com']['participationStatus'] ?? null);
    }

    public function testAnAddressIsOnePersonWhateverCaseItIsWrittenIn(): void
    {
        $ics = $this->calendar(<<<'ICS'
            ORGANIZER:mailto:Alice@Example.com
            ATTENDEE;PARTSTAT=ACCEPTED:mailto:alice@example.com
            ICS);

        $event = $this->converter()->toRemoteEvent($ics, '/c/1.ics', null);

        self::assertCount(1, $event?->jscalendar['participants'] ?? []);
    }

    public function testBothRolesSurviveTheWayBackOutAsTwoLines(): void
    {
        // The reverse of the merge, and the reason a round trip through plMail
        // does not quietly demote the organiser to an ordinary invitee.
        $event = CalDavFixture::event(jscalendar: [
            'participants' => [
                'alice@example.com' => [
                    '@type'               => 'Participant',
                    'email'               => 'alice@example.com',
                    'name'                => 'Alice Smith',
                    'roles'               => ['owner' => true, 'attendee' => true],
                    'participationStatus' => 'accepted',
                ],
            ],
        ]);

        $ics = $this->converter()->toIcs($event);

        self::assertStringContainsString('ORGANIZER;CN=Alice Smith:mailto:alice@example.com', $ics);
        self::assertStringContainsString('mailto:alice@example.com', $ics);
        self::assertStringContainsString('PARTSTAT=ACCEPTED', $ics);
        self::assertStringContainsString('ATTENDEE', $ics);
    }

    public function testAllDayEventsAreWrittenAsDatesRatherThanDateTimes(): void
    {
        // A date-time where a DATE was meant is shifted by the reader's offset,
        // which is how a birthday arrives on the wrong day.
        $ics = $this->converter()->toIcs(CalDavFixture::event(isAllDay: true));

        self::assertStringContainsString('DTSTART;VALUE=DATE:20260804', $ics);
        self::assertStringNotContainsString('DTSTART;VALUE=DATE:20260804T', $ics);
    }

    public function testAZonedEventCarriesItsZoneRatherThanBeingFlattenedToUtc(): void
    {
        // TZID is what lets the server and every other client re-render the
        // time after a DST change; a UTC instant cannot be re-rendered.
        $ics = $this->converter()->toIcs(CalDavFixture::event());

        self::assertStringContainsString('DTSTART;TZID=Europe/Berlin:20260804T100000', $ics);
    }

    public function testAllDayEventsComeBackAllDay(): void
    {
        $ics = $this->calendar('', 'DTSTART;VALUE=DATE:20260804', 'DTEND;VALUE=DATE:20260805');

        $event = $this->converter()->toRemoteEvent($ics, '/c/1.ics', null);

        self::assertTrue($event?->jscalendar['showWithoutTime'] ?? false);
        self::assertSame('P1D', $event?->jscalendar['duration'] ?? null);
    }

    public function testAnEventWithNoEndGetsANominalHourRatherThanNoLength(): void
    {
        // A zero-length row is invisible in every view, and an invite that says
        // nothing about its length is not an invite to nothing.
        $ics = $this->calendar('', 'DTSTART:20260804T080000Z', '');

        $event = $this->converter()->toRemoteEvent($ics, '/c/1.ics', null);

        self::assertSame('PT1H', $event?->jscalendar['duration'] ?? null);
        self::assertSame('2026-08-04 09:00:00', $event?->endsAt?->format('Y-m-d H:i:s'));
    }

    public function testTheSeriesMasterWinsOverAnEditedInstance(): void
    {
        // A CalDAV resource holds every component sharing one UID, so a moved
        // instance arrives beside its series. Taking whichever came first would
        // report that instance's time as the series' own.
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\nUID:weekly-1\r\nRECURRENCE-ID:20260811T080000Z\r\nDTSTART:20260811T140000Z\r\n"
            . "DTEND:20260811T150000Z\r\nSUMMARY:Moved instance\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:weekly-1\r\nDTSTART:20260804T080000Z\r\nDTEND:20260804T090000Z\r\n"
            . "SUMMARY:Weekly\r\nRRULE:FREQ=WEEKLY;BYDAY=TU\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $event = $this->converter()->toRemoteEvent($ics, '/c/1.ics', null);

        self::assertSame('Weekly', $event?->jscalendar['title'] ?? null);
        self::assertSame('2026-08-04 08:00:00', $event?->startsAt?->format('Y-m-d H:i:s'));
    }

    public function testARecurringEventArrivesAsARuleTheExpanderCanRead(): void
    {
        // The bug this replaced: the rule was kept verbatim under `plmail:rrule`
        // because RRULE→JSCalendar did not exist, and RecurrenceMaterialiser
        // reads recurrenceRules and nothing else — so a weekly meeting on a
        // CalDAV server was drawn here exactly once, on the day the series
        // started.
        $ics = $this->calendar('RRULE:FREQ=WEEKLY;BYDAY=TU,TH');

        $event = $this->converter()->toRemoteEvent($ics, '/c/1.ics', null);

        self::assertSame([[
            '@type'     => 'RecurrenceRule',
            'frequency' => 'weekly',
            'byDay'     => [
                ['@type' => 'NDay', 'day' => 'tu'],
                ['@type' => 'NDay', 'day' => 'th'],
            ],
        ]], $event?->jscalendar['recurrenceRules'] ?? null);

        self::assertArrayNotHasKey(
            'plmail:rrule',
            $event->jscalendar ?? [],
            'a converted rule must not also be stored verbatim, or the two can disagree',
        );
    }

    public function testARuleThatCannotBeConvertedIsStillKeptVerbatim(): void
    {
        // FREQ=SECONDLY is refused by the converter — sabre's iterator accepts
        // it and then never advances — but dropping it entirely would also drop
        // it on the way back out, and a push would un-repeat the series at the
        // server.
        $ics = $this->calendar('RRULE:FREQ=SECONDLY;COUNT=5');

        $event = $this->converter()->toRemoteEvent($ics, '/c/1.ics', null);

        self::assertArrayNotHasKey('recurrenceRules', $event->jscalendar ?? []);
        self::assertSame('FREQ=SECONDLY;COUNT=5', $event?->jscalendar['plmail:rrule'] ?? null);
    }

    public function testAMovedInstanceBecomesAnOverrideOnTheSeriesRatherThanBeingLost(): void
    {
        // A CalDAV resource holds every component sharing one UID, so the moved
        // instance is right there beside the series. Read as nothing — which is
        // what happened before — the series goes on drawing it at 10:00 on the
        // 11th, where nobody is.
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\nUID:weekly-1\r\nDTSTART;TZID=Europe/Berlin:20260804T100000\r\n"
            . "DTEND;TZID=Europe/Berlin:20260804T103000\r\nSUMMARY:Standup\r\nRRULE:FREQ=WEEKLY\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:weekly-1\r\nRECURRENCE-ID;TZID=Europe/Berlin:20260811T100000\r\n"
            . "DTSTART;TZID=Europe/Berlin:20260811T160000\r\nDTEND;TZID=Europe/Berlin:20260811T170000\r\n"
            . "SUMMARY:Standup (moved to the afternoon)\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $event = $this->converter()->toRemoteEvent($ics, '/c/1.ics', null);

        self::assertSame([
            // Keyed by where the rule put it, never by where it went: that is
            // the only name the instance keeps, and it is what the expander
            // looks the patch up by.
            '2026-08-11T10:00:00' => [
                '@type'    => 'Event',
                'start'    => '2026-08-11T16:00:00',
                'duration' => 'PT1H',
                'title'    => 'Standup (moved to the afternoon)',
            ],
        ], $event?->jscalendar['recurrenceOverrides'] ?? null);

        self::assertSame('2026-08-04T10:00:00', $event->jscalendar['start'] ?? null, 'the series itself has not moved');
    }

    public function testACancelledInstanceIsAnOverrideAndNotAWholeSeriesCancelled(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\nUID:weekly-1\r\nDTSTART:20260804T080000Z\r\nDTEND:20260804T083000Z\r\n"
            . "SUMMARY:Standup\r\nRRULE:FREQ=WEEKLY\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:weekly-1\r\nRECURRENCE-ID:20260811T080000Z\r\nDTSTART:20260811T080000Z\r\n"
            . "DTEND:20260811T083000Z\r\nSUMMARY:Standup\r\nSTATUS:CANCELLED\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $event = $this->converter()->toRemoteEvent($ics, '/c/1.ics', null);

        self::assertSame('confirmed', $event?->jscalendar['status'] ?? null, 'one instance is off, not the series');
        self::assertSame(
            'cancelled',
            $event->jscalendar['recurrenceOverrides']['2026-08-11T08:00:00']['status'] ?? null,
        );
    }

    public function testAnExdateTakesItsInstanceOffTheCalendar(): void
    {
        // EXDATE is the only place an .ics says an instance was cancelled when
        // no replacement component was written for it, and it has its own TZID:
        // compared against a UTC expansion it misses by an hour and takes the
        // wrong instance off, or none.
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\nUID:weekly-1\r\nDTSTART;TZID=Europe/Berlin:20260804T100000\r\n"
            . "DTEND;TZID=Europe/Berlin:20260804T103000\r\nSUMMARY:Standup\r\nRRULE:FREQ=WEEKLY\r\n"
            . "EXDATE;TZID=Europe/Berlin:20260811T100000,20260818T100000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $event = $this->converter()->toRemoteEvent($ics, '/c/1.ics', null);

        self::assertSame([
            '2026-08-11T10:00:00' => ['excluded' => true],
            '2026-08-18T10:00:00' => ['excluded' => true],
        ], $event?->jscalendar['recurrenceOverrides'] ?? null);
    }

    public function testAnExdateOnAnEventThatDoesNotRepeatIsNotAnOverrideOfAnything(): void
    {
        $ics = $this->calendar('EXDATE:20260811T080000Z');

        $event = $this->converter()->toRemoteEvent($ics, '/c/1.ics', null);

        self::assertArrayNotHasKey('recurrenceOverrides', $event->jscalendar ?? []);
    }

    public function testTheOverridesGoBackOutOrTheServerLosesThem(): void
    {
        // A PUT replaces the whole resource. Written with the master alone —
        // which is what this converter did before it read overrides at all — a
        // corrected typo in the title deletes every moved instance at the
        // server and every cancelled one comes back.
        $event = CalDavFixture::event(jscalendar: [
            'recurrenceRules'     => [['@type' => 'RecurrenceRule', 'frequency' => 'weekly']],
            'recurrenceOverrides' => [
                '2026-08-11T10:00:00' => [
                    '@type'    => 'Event',
                    'start'    => '2026-08-11T16:00:00',
                    'duration' => 'PT1H',
                    'title'    => 'Moved',
                ],
                '2026-08-18T10:00:00' => ['excluded' => true],
            ],
        ]);

        $ics = $this->converter()->toIcs($event);

        self::assertStringContainsString('RECURRENCE-ID;TZID=Europe/Berlin:20260811T100000', $ics);
        self::assertStringContainsString('DTSTART;TZID=Europe/Berlin:20260811T160000', $ics);
        self::assertStringContainsString('DTEND;TZID=Europe/Berlin:20260811T170000', $ics);
        self::assertStringContainsString('SUMMARY:Moved', $ics);
        self::assertStringContainsString('EXDATE;TZID=Europe/Berlin:20260818T100000', $ics);
        self::assertSame(2, substr_count($ics, 'BEGIN:VEVENT'), 'the master and the one moved instance');
    }

    public function testARuleFromTheServerIsWrittenBackUnchangedWhenNothingLocalReplacedIt(): void
    {
        $event = CalDavFixture::event(jscalendar: ['plmail:rrule' => 'FREQ=WEEKLY;BYDAY=TU,TH']);

        self::assertStringContainsString('RRULE:FREQ=WEEKLY;BYDAY=TU,TH', $this->converter()->toIcs($event));
    }

    public function testALocalRuleWinsOverTheOneThatCameOffTheServer(): void
    {
        // Preferring the verbatim rule would push an event back with the repeat
        // the user just changed away from.
        $event = CalDavFixture::event(jscalendar: [
            'plmail:rrule'     => 'FREQ=WEEKLY;BYDAY=TU,TH',
            'recurrenceRules'  => [['@type' => 'RecurrenceRule', 'frequency' => 'daily']],
        ]);

        $ics = $this->converter()->toIcs($event);

        self::assertStringContainsString('RRULE:FREQ=DAILY', $ics);
        self::assertStringNotContainsString('BYDAY=TU,TH', $ics);
    }

    public function testACancelledEventStaysAnEventRatherThanBecomingADeletion(): void
    {
        $ics = $this->calendar('STATUS:CANCELLED');

        $event = $this->converter()->toRemoteEvent($ics, '/c/1.ics', null);

        self::assertFalse($event?->isDeleted);
        self::assertSame('cancelled', $event?->jscalendar['status'] ?? null);
    }

    public function testAResourceThatIsNotAnEventIsSkippedRatherThanThrown(): void
    {
        // A CalDAV collection legitimately holds VTODOs, and an unreadable
        // resource must cost one event rather than the whole calendar.
        $task = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:t-1\r\nSUMMARY:Buy milk\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";

        self::assertNull($this->converter()->toRemoteEvent($task, '/c/1.ics', null));
        self::assertNull($this->converter()->toRemoteEvent('not a calendar at all', '/c/1.ics', null));
    }

    public function testAnEventWithoutAUidHasNoIdentityAndIsSkipped(): void
    {
        // Without a UID a later update could not find this again and every
        // resend would be a new event.
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nDTSTART:20260804T080000Z\r\nSUMMARY:Nameless\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        self::assertNull($this->converter()->toRemoteEvent($ics, '/c/1.ics', null));
    }

    public function testAZoneNobodyRecognisesLeavesTheEventFloatingRatherThanWrong(): void
    {
        // Exchange-flavoured names arrive here. Null means floating, which is
        // honest; guessing a zone moves the meeting.
        $ics = $this->calendar('', 'DTSTART;TZID=W. Europe Standard Time:20260804T100000');

        $event = $this->converter()->toRemoteEvent($ics, '/c/1.ics', null);

        self::assertNotNull($event);
        self::assertArrayNotHasKey('timeZone', $event->jscalendar ?? []);
    }

    public function testACollectionTimeZoneIsReadOutOfItsVtimezone(): void
    {
        $vtimezone = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n";

        self::assertSame('Europe/Berlin', $this->converter()->timeZoneOf($vtimezone));
        self::assertNull($this->converter()->timeZoneOf('nonsense'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function converter(): CalDavEventConverter
    {
        return new CalDavEventConverter(new RecurrenceRuleConverter(), new AlertReader(new NullLogger()));
    }

    /**
     * One VEVENT with whatever extra lines the case is about. Written with CRLF
     * because that is what a server sends and what sabre's forgiving reader is
     * given elsewhere.
     */
    private function calendar(
        string $extra = '',
        string $dtstart = 'DTSTART:20260804T080000Z',
        string $dtend = 'DTEND:20260804T090000Z',
    ): string {
        $lines = array_filter(array_merge(
            ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Test//EN', 'BEGIN:VEVENT', 'UID:standup-42', 'DTSTAMP:20260801T090000Z'],
            [$dtstart, $dtend],
            ['SUMMARY:Standup'],
            '' === $extra ? [] : explode("\n", $extra),
            ['END:VEVENT', 'END:VCALENDAR'],
        ), static fn (string $line): bool => '' !== trim($line));

        return implode("\r\n", array_map(trim(...), $lines)) . "\r\n";
    }
}
