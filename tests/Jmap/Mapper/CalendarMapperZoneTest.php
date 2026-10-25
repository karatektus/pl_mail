<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Mapper;

use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use App\Jmap\Mapper\CalendarMapper;
use App\Repository\Calendar\CalendarRepository;
use App\Service\Calendar\CalendarTimeResolver;
use App\Service\User\UserTimezoneResolver;
use PHPUnit\Framework\TestCase;

/**
 * A calendar following plMail stores null (#343), and JMAP reads a null
 * timeZone as floating time, so the published zone is the one in effect.
 */
final class CalendarMapperZoneTest extends TestCase
{
    public function testAFollowingCalendarPublishesItsOwnersZone(): void
    {
        $user           = new User();
        $user->timezone = 'Asia/Tokyo';

        $calendar      = new Calendar();
        $calendar->usr = $user;

        self::assertSame('Asia/Tokyo', $this->mapper()->toJmap($calendar, ['timeZone'])['timeZone']);
    }

    public function testAPinnedCalendarPublishesItsOwnZone(): void
    {
        $calendar           = new Calendar();
        $calendar->usr      = new User();
        $calendar->timeZone = 'America/New_York';

        self::assertSame('America/New_York', $this->mapper()->toJmap($calendar, ['timeZone'])['timeZone']);
    }

    private function mapper(): CalendarMapper
    {
        return new CalendarMapper(new CalendarTimeResolver(
            $this->createStub(CalendarRepository::class),
            new UserTimezoneResolver('Europe/Berlin'),
        ));
    }
}
