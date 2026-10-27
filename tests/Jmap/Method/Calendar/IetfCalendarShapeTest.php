<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Calendar;

use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Jmap\Method\Calendar\CalendarEventGetMethod;
use App\Jmap\Method\Calendar\CalendarEventSetMethod;
use App\Jmap\Method\Calendar\CalendarGetMethod;
use App\Jmap\Protocol\Capability;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\Protocol\StateChangeBuilder;
use App\Repository\Calendar\CalendarEventRepository;

/**
 * The calendar methods under the IETF draft's URN.
 *
 * The same methods answer plMail's URN unchanged (the other tests here); a
 * request whose `using` names the draft's is answered and read in the draft's
 * shape, which is what Stalwart speaks and what plMail's own JMAP client sends
 * — so one plMail can sync another's calendars. The writes are the part worth
 * pinning: a draft client echoes the whole event back with nulls for whatever
 * it holds none of, and that must not be refused, while a property plMail
 * would drop must still be.
 */
final class IetfCalendarShapeTest extends CalendarMethodTestCase
{
    private CalendarEventGetMethod $get;
    private CalendarEventSetMethod $set;
    private CalendarGetMethod $calendarGet;
    private CalendarEventRepository $events;

    private Calendar $calendar;
    private CalendarEvent $weekly;

    protected function setUp(): void
    {
        parent::setUp();

        $container         = self::getContainer();
        $this->get         = $container->get(CalendarEventGetMethod::class);
        $this->set         = $container->get(CalendarEventSetMethod::class);
        $this->calendarGet = $container->get(CalendarGetMethod::class);
        $this->events      = $container->get(CalendarEventRepository::class);

        $this->calendar = $this->seedCalendar('Work');
        $this->weekly   = $this->seedEvent($this->calendar, 'Standup', $this->baseDay()->setTime(9, 0), '+15 minutes', ['@type' => 'RecurrenceRule', 'frequency' => 'weekly']);
    }

    public function testAnEventIsPublishedInTheDraftsShape(): void
    {
        $event = $this->published();

        self::assertSame([(string) $this->calendar->id => true], $event['calendarIds']);
        self::assertSame('weekly', $event['recurrenceRule']['frequency']);
        self::assertArrayNotHasKey('calendarId', $event);
        self::assertArrayNotHasKey('recurrenceRules', $event);
        self::assertArrayNotHasKey('isRecurring', $event, 'plMail-only, and not JSCalendar');
        self::assertTrue($event['plmail.org:isRecurring'], 'so it is published under a vendor name');
    }

    /**
     * A rule plMail could not convert is kept verbatim and expands to one
     * occurrence: the event carries a rule and does not recur. Only
     * isRecurring says so, which is why it stays under the draft's URN.
     */
    public function testAnEventWithARuleThatDoesNotRecurSaysSo(): void
    {
        $this->weekly->isRecurring = false;
        $this->em->flush();

        $event = $this->published();

        self::assertSame('weekly', $event['recurrenceRule']['frequency']);
        self::assertFalse($event['plmail.org:isRecurring']);
    }

    public function testThePropertyIsAskedForByItsVendorName(): void
    {
        $event = $this->get->handle(['accountId' => $this->accountId(), 'ids' => [(string) $this->weekly->id], 'properties' => ['id', 'plmail.org:isRecurring']], $this->ietf())['list'][0];

        self::assertTrue($event['plmail.org:isRecurring']);
    }

    public function testUnderPlmailsUrnNothingChanges(): void
    {
        $event = $this->get->handle(['accountId' => $this->accountId(), 'ids' => [(string) $this->weekly->id]], $this->context())['list'][0];

        self::assertSame((string) $this->calendar->id, $event['calendarId']);
        self::assertArrayHasKey('recurrenceRules', $event);
    }

    public function testACalendarCarriesTheDraftsRights(): void
    {
        $calendar = $this->calendarGet->handle(['accountId' => $this->accountId(), 'ids' => [(string) $this->calendar->id]], $this->ietf())['list'][0];

        self::assertTrue($calendar['myRights']['mayWriteAll']);
        self::assertArrayHasKey('mayUpdateAll', $calendar['myRights'], "plMail's own stay beside them");
    }

    /**
     * What a draft client sends on an edit: the event as it last read it,
     * server-set properties and all, and null for what it holds none of.
     */
    public function testAnEchoedEventWithNullsForTheRestIsAnUpdate(): void
    {
        $patch = array_merge($this->published(), [
            'title'          => 'Daily standup',
            'recurrenceRule' => ['@type' => 'RecurrenceRule', 'frequency' => 'daily'],
            'alerts'         => null,
            'participants'   => null,
            'keywords'       => null,
            'links'          => null,
        ]);
        unset($patch['uid']);

        $result = $this->set->handle(['accountId' => $this->accountId(), 'update' => [(string) $this->weekly->id => $patch]], $this->ietf());

        self::assertSame([], (array) $result['notUpdated']);
        self::assertSame('Daily standup', $this->reloaded()->title);
        self::assertSame('daily', $this->reloaded()->jscalendar['recurrenceRules'][0]['frequency']);
    }

    public function testACreateIsFiledByItsCalendarIds(): void
    {
        $result = $this->set->handle(['accountId' => $this->accountId(), 'create' => ['e' => [
            '@type'       => 'Event',
            'calendarIds' => [(string) $this->calendar->id => true],
            'title'       => 'Planning',
            'start'       => $this->baseDay()->format('Y-m-d') . 'T14:00:00',
            'timeZone'    => 'UTC',
            'duration'    => 'PT1H',
            'privacy'     => 'public',
        ]]], $this->ietf());

        $created = ((array) $result['created'])['e'];

        self::assertSame([(string) $this->calendar->id => true], $created['calendarIds']);
        self::assertFalse($created['plmail.org:isRecurring']);
        self::assertSame('Planning', $this->events->find((int) $created['id'])?->title);
    }

    /** Alerts plMail cannot keep are refused by name, not taken and lost. */
    public function testAPropertyPlmailWouldDropIsStillRefused(): void
    {
        $result = $this->set->handle(['accountId' => $this->accountId(), 'update' => [(string) $this->weekly->id => [
            'alerts' => ['a' => ['@type' => 'Alert', 'trigger' => ['@type' => 'OffsetTrigger', 'offset' => '-PT5M']]],
        ]]], $this->ietf());

        self::assertSame(['alerts'], ((array) $result['notUpdated'])[(string) $this->weekly->id]['properties']);
    }

    public function testAnEventIsInExactlyOneCalendar(): void
    {
        $other = $this->seedCalendar('Home');

        $result = $this->set->handle(['accountId' => $this->accountId(), 'update' => [(string) $this->weekly->id => [
            'calendarIds' => [(string) $this->calendar->id => true, (string) $other->id => true],
        ]]], $this->ietf());

        self::assertSame(['calendarIds'], ((array) $result['notUpdated'])[(string) $this->weekly->id]['properties']);
    }

    /** A change to an event is pushed, under the account that publishes the calendars. */
    public function testAStateChangeNamesTheCalendarTypes(): void
    {
        $snapshot = self::getContainer()->get(StateChangeBuilder::class)->snapshot($this->user);

        self::assertArrayHasKey('CalendarEvent', $snapshot[$this->accountId()]);
        self::assertArrayHasKey('Calendar', $snapshot[$this->accountId()]);
    }

    private function ietf(): JmapContext
    {
        return new JmapContext($this->user, [], [Capability::CORE, Capability::IETF_CALENDARS]);
    }

    /** @return array<string,mixed> */
    private function published(): array
    {
        return $this->get->handle(['accountId' => $this->accountId(), 'ids' => [(string) $this->weekly->id]], $this->ietf())['list'][0];
    }

    private function reloaded(): CalendarEvent
    {
        $this->em->clear();

        return $this->events->find((int) $this->weekly->id) ?? throw new \LogicException('The event is gone.');
    }
}
