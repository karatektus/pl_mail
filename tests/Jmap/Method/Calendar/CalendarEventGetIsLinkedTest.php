<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Calendar;

use App\Jmap\Method\Calendar\CalendarEventGetMethod;

/**
 * `isLinked` on CalendarEvent/get (a plMail extension): false for an event
 * typed in by hand, and only sent when asked for or when everything is.
 */
final class CalendarEventGetIsLinkedTest extends CalendarMethodTestCase
{
    public function testAnEventTypedInIsNotLinkedAndTheFlagIsOnlySentWhenAsked(): void
    {
        $calendar = $this->seedCalendar('Home');
        $event    = $this->seedEvent($calendar, 'Hair', $this->baseDay()->setTime(10, 0));
        $method   = self::getContainer()->get(CalendarEventGetMethod::class);

        $asked = $method->handle([
            'accountId'  => $this->accountId(),
            'ids'        => [(string) $event->id],
            'properties' => ['id', 'title', 'isLinked'],
        ], $this->context());
        self::assertFalse($asked['list'][0]['isLinked']);

        $everything = $method->handle([
            'accountId' => $this->accountId(),
            'ids'       => [(string) $event->id],
        ], $this->context());
        self::assertFalse($everything['list'][0]['isLinked']);

        $notAsked = $method->handle([
            'accountId'  => $this->accountId(),
            'ids'        => [(string) $event->id],
            'properties' => ['id', 'title'],
        ], $this->context());
        self::assertArrayNotHasKey('isLinked', $notAsked['list'][0]);
    }
}
