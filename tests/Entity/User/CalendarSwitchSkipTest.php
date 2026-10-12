<?php

declare(strict_types=1);

namespace App\Tests\Entity\User;

use App\Domain\Enum\Calendar\CalendarSwitchSkip;
use App\Entity\User\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which position the calendar button leaves out (Settings → Calendar →
 * Behaviour), as the settings bag keeps it.
 */
final class CalendarSwitchSkipTest extends TestCase
{
    /** None for everybody, and nothing stored for the default, like every setting in the bag. */
    public function testTheButtonCyclesUntilSomebodyChangesIt(): void
    {
        $user = new User();

        self::assertSame(CalendarSwitchSkip::None, $user->calendarSwitchSkip);
        self::assertNull($user->getSetting(User::SETTING_CALENDAR_SWITCH_SKIP));
    }

    public function testAChoiceIsStoredAsItsValueAndReadBack(): void
    {
        $user = new User();

        $user->calendarSwitchSkip = CalendarSwitchSkip::Split;
        self::assertSame('split', $user->getSetting(User::SETTING_CALENDAR_SWITCH_SKIP));
        self::assertSame(CalendarSwitchSkip::Split, $user->calendarSwitchSkip);

        // Straight to the other one: there is no first choice to switch off.
        $user->calendarSwitchSkip = CalendarSwitchSkip::Calendar;
        self::assertSame('calendar', $user->getSetting(User::SETTING_CALENDAR_SWITCH_SKIP));
        self::assertSame(CalendarSwitchSkip::Calendar, $user->calendarSwitchSkip);
    }

    public function testChoosingAllThreeAgainLeavesTheDefaultRatherThanAStoredNone(): void
    {
        $user                     = new User();
        $user->calendarSwitchSkip = CalendarSwitchSkip::Split;

        $user->calendarSwitchSkip = CalendarSwitchSkip::None;

        self::assertNull($user->getSetting(User::SETTING_CALENDAR_SWITCH_SKIP));
        self::assertSame(CalendarSwitchSkip::None, $user->calendarSwitchSkip);
    }

    /**
     * A bag written some other way — a backup edited by hand — reads as the
     * default cycle rather than failing the page that asks.
     */
    #[DataProvider('unusable')]
    public function testAStoredValueThatIsNotAChoiceReadsAsTheDefaultCycle(mixed $stored): void
    {
        $user = new User();
        $user->setSetting(User::SETTING_CALENDAR_SWITCH_SKIP, $stored);

        self::assertSame(CalendarSwitchSkip::None, $user->calendarSwitchSkip);
    }

    /** @return iterable<string, array{mixed}> */
    public static function unusable(): iterable
    {
        yield 'an unknown name' => ['both'];
        yield 'a boolean'       => [true];
        yield 'a number'        => [1];
        yield 'a list'          => [['split']];
    }
}
