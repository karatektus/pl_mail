<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventOccurrenceRepository;
use App\Repository\Calendar\CalendarRepository;
use App\Service\Calendar\CalendarTimeResolver;
use App\Service\Calendar\UpcomingEventIndicator;
use App\Service\Calendar\UserCalendars;
use App\Service\User\UserTimezoneResolver;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * "Today" in the topbar dot is the end of the user's local day, and which zone
 * that day is local to is the same decision the calendar grid makes — so the
 * dot has to ask the same resolver, not read the default calendar's column
 * itself. It did, and a mirrored default calendar stamped UTC put the dot's
 * "today" two hours off the grid it sits above.
 *
 * Observed through the upper bound handed to the occurrence query, because that
 * bound is the whole of what the zone decides here.
 */
final class UpcomingEventIndicatorZoneTest extends TestCase
{
    /**
     * 23:30 UTC on the 14th is 01:30 on the 15th in Paris (CEST, +02:00), so the
     * user's "today" ends at the next local midnight — 22:00 UTC on the 15th.
     * Read in UTC it would end at 00:00 UTC on the 15th, half an hour from now.
     */
    public function testAMirroredDefaultCalendarDoesNotMoveTodaysEnd(): void
    {
        $mirror           = $this->calendar(1, 'UTC');
        $mirror->role     = CalendarRole::Remote;
        $mirror->remoteId = 'family@group.calendar.google.com';

        self::assertSame('2026-10-15 22:00:00', $this->windowEnd($mirror, 'Europe/Paris'));
    }

    /**
     * Presence companion: a calendar this install owns still decides. UTC here
     * is a deliberate choice, so the same instant ends "today" at 00:00 UTC —
     * which a resolver that ignored the calendar entirely could not produce.
     */
    public function testALocalDefaultCalendarStillDecidesTodaysEnd(): void
    {
        self::assertSame('2026-10-15 00:00:00', $this->windowEnd($this->calendar(1, 'UTC'), 'Europe/Paris'));
    }

    /** The upper bound the occurrence query was asked for, as a UTC wall string. */
    private function windowEnd(Calendar $default, string $userZone): string
    {
        $default->isDefault = true;

        $repository = $this->createStub(CalendarRepository::class);
        $repository->method('findForUser')->willReturn([$default]);
        $repository->method('findDefaultForUser')->willReturn($default);

        $captured    = null;
        $occurrences = $this->createStub(CalendarEventOccurrenceRepository::class);
        $occurrences->method('findInRange')->willReturnCallback(
            static function ($user, array $ids, DateTimeImmutable $from, DateTimeImmutable $to) use (&$captured): array {
                $captured = $to;

                return [];
            },
        );

        $indicator = new UpcomingEventIndicator(
            new UserCalendars($repository),
            $occurrences,
            new CalendarTimeResolver($repository, new UserTimezoneResolver('Europe/Berlin')),
        );

        $user           = new User();
        $user->timezone = $userZone;

        $indicator->forUser($user, new DateTimeImmutable('2026-10-14 23:30:00', new DateTimeZone('UTC')));

        self::assertInstanceOf(DateTimeImmutable::class, $captured);

        return $captured->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function calendar(int $id, string $zone): Calendar
    {
        $calendar           = new Calendar();
        $calendar->timeZone = $zone;

        new ReflectionProperty(Calendar::class, 'id')->setValue($calendar, $id);

        return $calendar;
    }
}
