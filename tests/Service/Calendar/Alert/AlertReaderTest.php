<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Alert;

use App\Domain\Enum\Calendar\AlertAction;
use App\Entity\Calendar\CalendarEvent;
use App\Service\Calendar\Alert\AlertReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An alert's key is derived from what it does, and the editor can only tick
 * alerts that already exist.
 *
 * Two claims, and they are the two that everything else in this feature rests
 * on.
 *
 * **The key is a pure function of the trigger and the action.** A delivery
 * record names an alert by key, so a key that changed because somebody fixed a
 * typo in the title would leave the record pointing at an alert that no longer
 * exists — and the alert would go off a second time, which is the one thing the
 * whole delivery table is there to prevent. It also means the same reminder
 * arriving from Google today and from a CalDAV mirror tomorrow is one entry
 * rather than two.
 *
 * **What the form posts is a key, not a value.** The editor renders a fixed list
 * and the save resolves against that same list, so a crafted post can untick
 * things but cannot invent one — and an alarm the form has no field for (an
 * absolute trigger, one measured from the end) survives a save rather than being
 * silently dropped by a round trip through a UI that cannot express it.
 *
 * A plain TestCase: AlertReader takes nothing but a logger and touches no
 * database, so a booted kernel would only make the suite slower.
 */
final class AlertReaderTest extends TestCase
{
    private AlertReader $reader;

    protected function setUp(): void
    {
        $this->reader = new AlertReader(new NullLogger());
    }

    /**
     * The same alert, described twice, is one key. Were it not, an event saved
     * twice would carry two identical reminders and fire both.
     */
    public function testTheSameTriggerAndActionAlwaysProduceTheSameKey(): void
    {
        $first  = $this->reader->offsetAlert('-PT10M', AlertAction::Display);
        $second = $this->reader->offsetAlert('-PT10M', AlertAction::Display);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->key, $second->key);
    }

    /** The action is part of the identity: a popup and a mail are two reminders. */
    public function testTwoActionsAtTheSameOffsetAreTwoAlerts(): void
    {
        $display = $this->reader->offsetAlert('-PT1H', AlertAction::Display);
        $email   = $this->reader->offsetAlert('-PT1H', AlertAction::Email);

        self::assertNotNull($display);
        self::assertNotNull($email);
        self::assertNotSame($display->key, $email->key);
    }

    /**
     * A key nobody rendered is not an alert.
     *
     * The form field is a checkbox value and therefore editable by whoever
     * submits it. Resolving against the rendered list rather than parsing the
     * value is what stops a post inventing "-P365D, by email".
     */
    public function testAKeyThatWasNeverOfferedIsDroppedRatherThanBecomingAnAlert(): void
    {
        $chosen = $this->reader->chosen(null, ['email/-P365D', 'display/-PT10M']);

        self::assertCount(1, $chosen, 'only the offered key survives');
        self::assertSame('display/-PT10M', $chosen[0]->key);
    }

    /**
     * An alarm the editor has no field for still round-trips.
     *
     * This is the reason the checkbox posts a key. An absolute trigger cannot be
     * expressed by any control in the form, so a form that posted offsets would
     * lose it on the first save — and the reminder somebody set in Thunderbird
     * would vanish the moment they renamed the meeting here.
     */
    public function testAnAlarmTheFormCannotExpressSurvivesBeingTicked(): void
    {
        $event = $this->eventWithAlerts([
            'imported-1' => [
                '@type'   => 'Alert',
                'trigger' => ['@type' => 'AbsoluteTrigger', 'when' => '2026-08-05T08:00:00Z'],
                'action'  => 'display',
            ],
        ]);

        $chosen = $this->reader->chosen($event, ['imported-1']);

        self::assertCount(1, $chosen);
        self::assertNotNull($chosen[0]->absoluteAt);
        self::assertSame('2026-08-05T08:00:00Z', $chosen[0]->absoluteAt->format('Y-m-d\TH:i:s\Z'));
    }

    /**
     * The stored key is kept, not re-derived.
     *
     * Another client keys its alerts however it likes. Renaming one here would
     * orphan every delivery record already written against it, which reads to
     * the sweep as "never delivered".
     */
    public function testAKeyThatArrivedFromElsewhereIsKeptRatherThanRenamed(): void
    {
        $event = $this->eventWithAlerts([
            'X-THUNDERBIRD-42' => [
                '@type'   => 'Alert',
                'trigger' => ['@type' => 'OffsetTrigger', 'offset' => '-PT15M'],
                'action'  => 'display',
            ],
        ]);

        $alerts = $this->reader->alertsOf($event);

        self::assertCount(1, $alerts);
        self::assertSame('X-THUNDERBIRD-42', $alerts[0]->key);
    }

    /**
     * The offset string survives untouched.
     *
     * -P1W and -P7D name the same instant, and rewriting one as the other makes
     * every CalDAV push look like a change to a server comparing two versions of
     * the resource.
     */
    public function testAnOffsetIsWrittenBackExactlyAsItArrived(): void
    {
        $event = $this->eventWithAlerts([
            'a' => [
                '@type'   => 'Alert',
                'trigger' => ['@type' => 'OffsetTrigger', 'offset' => '-P1W'],
                'action'  => 'display',
            ],
        ]);

        $alerts = $this->reader->alertsOf($event);

        self::assertSame('-P1W', $alerts[0]->offset);
        self::assertSame(-604800, $alerts[0]->offsetSeconds, 'a week, measured rather than re-spelled');
        self::assertSame('-P1W', $alerts[0]->toJsCalendar()['trigger']['offset']);
    }

    /** `relativeTo` is read, so an alarm measured from the end stays measured from the end. */
    public function testAnAlertMeasuredFromTheEndIsNotSilentlyMeasuredFromTheStart(): void
    {
        $event = $this->eventWithAlerts([
            'a' => [
                '@type'   => 'Alert',
                'trigger' => ['@type' => 'OffsetTrigger', 'offset' => '-PT5M', 'relativeTo' => 'end'],
                'action'  => 'display',
            ],
        ]);

        $alerts = $this->reader->alertsOf($event);

        self::assertTrue($alerts[0]->relativeToEnd);
        self::assertSame('end', $alerts[0]->toJsCalendar()['trigger']['relativeTo']);
    }

    /**
     * An empty custom field posts zero, and zero must not mean "at the time of
     * the event" — that would give every save an alert the user never asked for,
     * on every event, forever.
     */
    public function testAnEmptyCustomFieldAddsNothing(): void
    {
        self::assertNull($this->reader->customAlert(0, AlertAction::Display));
        self::assertNull($this->reader->customAlert(null, AlertAction::Display));
        self::assertNull($this->reader->customAlert(-5, AlertAction::Display));
    }

    /** Past the horizon the sweep reads, an alert would be stored and never fire. */
    public function testACustomLeadBeyondWhatCanEverFireIsRefused(): void
    {
        self::assertNull($this->reader->customAlert(AlertReader::MAX_CUSTOM_MINUTES + 1, AlertAction::Display));
        self::assertNotNull($this->reader->customAlert(AlertReader::MAX_CUSTOM_MINUTES, AlertAction::Display));
    }

    /**
     * A hostile .ics is allowed to claim forty thousand alarms; each would be a
     * delivery row and a push notification.
     */
    public function testAnEventCarryingMoreAlertsThanAreHonouredIsTrimmed(): void
    {
        $alerts = [];

        for ($minute = 1; $minute <= AlertReader::MAX_ALERTS + 5; ++$minute) {
            $alerts[sprintf('a%d', $minute)] = [
                '@type'   => 'Alert',
                'trigger' => ['@type' => 'OffsetTrigger', 'offset' => sprintf('-PT%dM', $minute)],
                'action'  => 'display',
            ];
        }

        self::assertCount(AlertReader::MAX_ALERTS, $this->reader->alertsOf($this->eventWithAlerts($alerts)));
    }

    /** One malformed entry costs itself, never the alerts beside it. */
    public function testAnUnreadableAlertDoesNotCostTheOnesBesideIt(): void
    {
        $event = $this->eventWithAlerts([
            'broken' => ['@type' => 'Alert', 'trigger' => ['@type' => 'OffsetTrigger', 'offset' => 'nonsense']],
            'good'   => ['@type' => 'Alert', 'trigger' => ['@type' => 'OffsetTrigger', 'offset' => '-PT10M']],
        ]);

        $alerts = $this->reader->alertsOf($event);

        self::assertCount(1, $alerts);
        self::assertSame('good', $alerts[0]->key);
    }

    /**
     * The six one-click choices are always offered, and a stored alert that
     * happens to be one of them is offered once rather than twice.
     */
    public function testAStoredCommonOffsetIsNotOfferedTwice(): void
    {
        $alert = $this->reader->offsetAlert('-PT30M', AlertAction::Display);

        self::assertNotNull($alert);

        $event   = $this->eventWithAlerts([$alert->key => $alert->toJsCalendar()]);
        $choices = $this->reader->choicesFor($event);

        self::assertCount(count(AlertReader::COMMON_OFFSETS), $choices);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @param array<string,array<string,mixed>> $alerts
     */
    private function eventWithAlerts(array $alerts): CalendarEvent
    {
        $event             = new CalendarEvent();
        $event->jscalendar = ['@type' => 'Event', 'alerts' => $alerts];

        return $event;
    }
}
