<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Alert;

use App\Domain\Enum\Calendar\AlertAction;
use App\Entity\Calendar\CalendarEvent;
use App\Service\Calendar\Alert\AlertReader;
use App\Service\Calendar\RecurrenceRuleConverter;
use App\Service\Calendar\Sync\CalDav\CalDavEventConverter;
use App\Service\Calendar\Sync\Google\GoogleEventMapper;
use App\Service\Calendar\Sync\Google\GoogleRecurrenceMapper;
use App\Service\Calendar\Sync\Graph\GraphEventMapper;
use App\Service\Calendar\Sync\Graph\GraphRecurrenceMapper;
use App\Service\Calendar\Sync\Graph\GraphTimeZoneMapper;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A reminder set anywhere else survives being mirrored here.
 *
 * All three drivers used to drop alerts on the way in and never write them on
 * the way out, and each said so in its own docblock: there was no local feature
 * to honour a reminder with, so carrying one would have been a promise nothing
 * kept. That is no longer true, and the failure it turns into is specific — a
 * reminder set in Outlook or Thunderbird disappears the first time somebody
 * corrects the title of the meeting in plMail, because a push writes back
 * everything the mapper knows about and silently omits everything it does not.
 *
 * The three providers say the same thing in three shapes, and each has a trap:
 *
 *   **Google** distinguishes an event's own reminders from the calendar's
 *   defaults with `useDefault`, and returns the defaults in `overrides` even
 *   when the flag is set. Reading them as event alerts would make plMail notify
 *   beside Google for every event on a calendar that has a default reminder.
 *
 *   **Graph** has room for exactly one reminder, so several local alerts have to
 *   collapse to one — and `isReminderOn: false` has to be written, or an alert
 *   removed here goes on firing in Outlook forever.
 *
 *   **CalDAV** is iCalendar, where the trigger's meaning is in its parameters:
 *   `VALUE=DATE-TIME` and `RELATED=END` are the difference between "at ten
 *   o'clock" and "ten minutes before", and telling them apart by looking at the
 *   string is how that goes wrong.
 *
 * Plain TestCase: all three mappers are pure functions of one decoded resource
 * or one local row, which is the whole reason they are separate classes.
 */
final class AlertSyncRoundTripTest extends TestCase
{
    private AlertReader $alerts;

    protected function setUp(): void
    {
        $this->alerts = new AlertReader(new NullLogger());
    }

    // ── Google ────────────────────────────────────────────────────────────

    public function testAGoogleReminderArrivesAsAnAlert(): void
    {
        $remote = $this->google()->toRemoteEvent([
            'id'        => 'g1',
            'iCalUID'   => 'g1@google',
            'summary'   => 'Standup',
            'start'     => ['dateTime' => '2026-06-02T09:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
            'end'       => ['dateTime' => '2026-06-02T09:30:00+02:00', 'timeZone' => 'Europe/Berlin'],
            'reminders' => [
                'useDefault' => false,
                'overrides'  => [
                    ['method' => 'popup', 'minutes' => 10],
                    ['method' => 'email', 'minutes' => 60],
                ],
            ],
        ], 'Europe/Berlin');

        self::assertNotNull($remote);

        $keys = array_keys($remote->jscalendar['alerts']);

        self::assertSame(['display/-PT10M', 'email/-PT1H'], $keys);
    }

    /**
     * The calendar's defaults are the calendar's.
     *
     * Google sends them in `overrides` on a read regardless, so a mapper that
     * only looked at that key would give every event on such a calendar an alert
     * plMail then fires beside the one Google already fires.
     */
    public function testGoogleCalendarDefaultsAreNotReadAsTheEventsOwnAlerts(): void
    {
        $remote = $this->google()->toRemoteEvent([
            'id'        => 'g2',
            'summary'   => 'Standup',
            'start'     => ['dateTime' => '2026-06-02T09:00:00Z'],
            'end'       => ['dateTime' => '2026-06-02T09:30:00Z'],
            'reminders' => [
                'useDefault' => true,
                'overrides'  => [['method' => 'popup', 'minutes' => 30]],
            ],
        ], 'UTC');

        self::assertNotNull($remote);
        self::assertArrayNotHasKey('alerts', $remote->jscalendar);
    }

    public function testAnAlertIsSentBackToGoogleAsAReminderOverride(): void
    {
        $payload = $this->google()->toGoogleEvent($this->eventWithAlerts(['-PT10M']));

        self::assertSame(
            ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => 10]]],
            $payload['reminders'],
        );
    }

    /**
     * An event with no alerts says nothing about reminders.
     *
     * The alternative — asserting `useDefault: false` with an empty overrides
     * list — clears the calendar's default reminders on every event this install
     * has ever touched, which is every event on it. Because the update is a
     * PATCH, saying nothing leaves them alone.
     */
    public function testAnEventWithNoAlertsDoesNotClearGooglesOwnReminders(): void
    {
        $payload = $this->google()->toGoogleEvent($this->eventWithAlerts([]));

        self::assertArrayNotHasKey('reminders', $payload);
    }

    // ── Graph ─────────────────────────────────────────────────────────────

    public function testAGraphReminderArrivesAsAnAlert(): void
    {
        $remote = $this->graph()->toRemoteEvent([
            'id'                         => 'm1',
            'iCalUId'                    => 'm1@graph',
            'subject'                    => 'Standup',
            'start'                      => ['dateTime' => '2026-06-02T09:00:00.0000000', 'timeZone' => 'UTC'],
            'end'                        => ['dateTime' => '2026-06-02T09:30:00.0000000', 'timeZone' => 'UTC'],
            'isReminderOn'               => true,
            'reminderMinutesBeforeStart' => 15,
        ]);

        self::assertNotNull($remote);
        self::assertSame(
            ['@type' => 'OffsetTrigger', 'offset' => '-PT15M'],
            $remote->jscalendar['alerts']['display/-PT15M']['trigger'],
        );
    }

    public function testAGraphEventWithItsReminderOffCarriesNoAlert(): void
    {
        $remote = $this->graph()->toRemoteEvent([
            'id'                         => 'm2',
            'subject'                    => 'Standup',
            'start'                      => ['dateTime' => '2026-06-02T09:00:00.0000000', 'timeZone' => 'UTC'],
            'end'                        => ['dateTime' => '2026-06-02T09:30:00.0000000', 'timeZone' => 'UTC'],
            'isReminderOn'               => false,
            'reminderMinutesBeforeStart' => 15,
        ]);

        self::assertNotNull($remote);
        self::assertArrayNotHasKey('alerts', $remote->jscalendar);
    }

    /**
     * Graph holds one reminder, so several become the one nearest the start —
     * the one that means "now". Sending the earliest instead would give an
     * Outlook user a day's warning and then silence at the moment it begins.
     */
    public function testSeveralAlertsBecomeTheGraphReminderNearestTheStart(): void
    {
        $payload = $this->graph()->toGraphEvent($this->eventWithAlerts(['-P1D', '-PT10M', '-PT1H']));

        self::assertTrue($payload['isReminderOn']);
        self::assertSame(10, $payload['reminderMinutesBeforeStart']);
    }

    /**
     * Unlike Google's, this one can be cleared: `false` is unambiguous, so an
     * alert removed here is removed in Outlook rather than firing forever.
     */
    public function testRemovingEveryAlertTurnsTheGraphReminderOff(): void
    {
        $payload = $this->graph()->toGraphEvent($this->eventWithAlerts([]));

        self::assertFalse($payload['isReminderOn']);
        self::assertArrayNotHasKey('reminderMinutesBeforeStart', $payload);
    }

    /** An email alert has no Graph counterpart and must not become a popup. */
    public function testAnEmailAlertDoesNotBecomeAGraphPopup(): void
    {
        $event = $this->eventWithAlerts([]);
        $alert = $this->alerts->offsetAlert('-PT30M', AlertAction::Email);

        self::assertNotNull($alert);

        $event->jscalendar['alerts'] = [$alert->key => $alert->toJsCalendar()];

        self::assertFalse($this->graph()->toGraphEvent($event)['isReminderOn']);
    }

    // ── CalDAV ────────────────────────────────────────────────────────────

    public function testAValarmArrivesAsAnAlert(): void
    {
        $remote = $this->caldav()->toRemoteEvent($this->ics(<<<'ICS'
            BEGIN:VALARM
            ACTION:DISPLAY
            DESCRIPTION:Standup
            TRIGGER:-PT15M
            END:VALARM
            ICS), '/dav/1.ics', '"etag"');

        self::assertNotNull($remote);
        self::assertSame(
            ['@type' => 'OffsetTrigger', 'offset' => '-PT15M'],
            $remote->jscalendar['alerts']['display/-PT15M']['trigger'],
        );
    }

    /** RELATED=END is a parameter, not something to infer from the string. */
    public function testAnAlarmRelatedToTheEndIsNotReadAsOneBeforeTheStart(): void
    {
        $remote = $this->caldav()->toRemoteEvent($this->ics(<<<'ICS'
            BEGIN:VALARM
            ACTION:DISPLAY
            DESCRIPTION:Standup
            TRIGGER;RELATED=END:-PT5M
            END:VALARM
            ICS), '/dav/2.ics', null);

        self::assertNotNull($remote);
        self::assertSame('end', $remote->jscalendar['alerts']['display/-PT5M:end']['trigger']['relativeTo']);
    }

    /**
     * `VALUE=DATE-TIME` makes the same property mean an instant rather than a
     * duration, and "20260602T083000Z" is not a duration any parser accepts —
     * so a mapper that ignored the parameter would drop the alarm entirely.
     */
    public function testAnAbsoluteAlarmArrivesAsAnAbsoluteTrigger(): void
    {
        $remote = $this->caldav()->toRemoteEvent($this->ics(<<<'ICS'
            BEGIN:VALARM
            ACTION:DISPLAY
            DESCRIPTION:Standup
            TRIGGER;VALUE=DATE-TIME:20260602T083000Z
            END:VALARM
            ICS), '/dav/3.ics', null);

        self::assertNotNull($remote);
        self::assertSame(
            ['@type' => 'AbsoluteTrigger', 'when' => '2026-06-02T08:30:00Z'],
            $remote->jscalendar['alerts']['display/abs:2026-06-02T08:30:00Z']['trigger'],
        );
    }

    /**
     * And back out again. The regression this guards is the one that made the
     * mapper carry alarms in the first place: without a VALARM in the payload, a
     * PUT that renames a meeting deletes the reminder somebody set for it.
     */
    public function testAnAlertIsWrittenBackAsAValarm(): void
    {
        $ics = $this->caldav()->toIcs($this->eventWithAlerts(['-PT10M']));

        self::assertStringContainsString('BEGIN:VALARM', $ics);
        self::assertStringContainsString('ACTION:DISPLAY', $ics);
        self::assertStringContainsString('TRIGGER:-PT10M', $ics);
        self::assertStringContainsString('DESCRIPTION:Standup', $ics);
    }

    public function testAnAlarmSurvivesAFullRoundTripThroughCalDav(): void
    {
        $converter = $this->caldav();

        $remote = $converter->toRemoteEvent($this->ics(<<<'ICS'
            BEGIN:VALARM
            ACTION:EMAIL
            DESCRIPTION:Standup
            SUMMARY:Standup
            TRIGGER:-P1D
            END:VALARM
            ICS), '/dav/4.ics', null);

        self::assertNotNull($remote);

        $event             = $this->eventWithAlerts([]);
        $event->jscalendar = $remote->jscalendar;

        $ics = $converter->toIcs($event);

        self::assertStringContainsString('ACTION:EMAIL', $ics);
        self::assertStringContainsString('TRIGGER:-P1D', $ics);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function google(): GoogleEventMapper
    {
        return new GoogleEventMapper(
            new GoogleRecurrenceMapper(new RecurrenceRuleConverter()),
            $this->alerts,
        );
    }

    private function graph(): GraphEventMapper
    {
        return new GraphEventMapper(new GraphTimeZoneMapper(), new GraphRecurrenceMapper(), $this->alerts);
    }

    private function caldav(): CalDavEventConverter
    {
        return new CalDavEventConverter(new RecurrenceRuleConverter(), $this->alerts);
    }

    /** One VEVENT with whatever VALARM the case is about folded into it. */
    private function ics(string $valarm): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//EN',
            'BEGIN:VEVENT',
            'UID:standup@example.test',
            'DTSTAMP:20260601T090000Z',
            'DTSTART:20260602T090000Z',
            'DTEND:20260602T093000Z',
            'SUMMARY:Standup',
            ...explode("\n", $valarm),
            'END:VEVENT',
            'END:VCALENDAR',
        ]);
    }

    /**
     * A local event carrying the given offsets as display alerts.
     *
     * Built by hand rather than through CalendarEventWriter, so this stays a
     * unit test of the mappers: the writer would need a database, and what is
     * under test is what the three of them do with an `alerts` map, not how one
     * gets there.
     *
     * @param list<string> $offsets
     */
    private function eventWithAlerts(array $offsets): CalendarEvent
    {
        $utc = new DateTimeZone('UTC');

        $event           = new CalendarEvent();
        $event->uid      = 'standup@example.test';
        $event->title    = 'Standup';
        $event->startsAt = new DateTimeImmutable('2026-06-02 09:00:00', $utc);
        $event->endsAt   = new DateTimeImmutable('2026-06-02 09:30:00', $utc);
        $event->timeZone = 'UTC';

        $alerts = [];

        foreach ($offsets as $offset) {
            $alert = $this->alerts->offsetAlert($offset, AlertAction::Display);

            self::assertNotNull($alert);

            $alerts[$alert->key] = $alert->toJsCalendar();
        }

        $event->jscalendar = ['@type' => 'Event', 'uid' => $event->uid, 'title' => 'Standup']
            + ([] === $alerts ? [] : ['alerts' => $alerts]);

        return $event;
    }
}
