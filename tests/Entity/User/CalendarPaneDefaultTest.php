<?php

declare(strict_types=1);

namespace App\Tests\Entity\User;

use App\Domain\Enum\Calendar\CalendarPaneMode;
use App\Entity\User\User;
use PHPUnit\Framework\TestCase;

/**
 * Where the calendar pane starts for somebody who has never moved the switch.
 *
 * Beside the mail: that is the product's default, and the demo relies on it
 * because a demo visitor never chooses anything. A phone still opens on its
 * mail, but that is ui--split's rule at arrival, not this default.
 */
final class CalendarPaneDefaultTest extends TestCase
{
    public function testNobodyWhoHasNotChosenStartsWithoutTheCalendar(): void
    {
        self::assertSame(CalendarPaneMode::Split, new User()->calendarPaneMode);
    }

    /**
     * The boolean the mode replaced is still honoured in the one direction
     * that is a choice: a pane somebody shut stays shut.
     */
    public function testAPaneShutUnderTheOldSettingStaysShut(): void
    {
        $user = new User();
        $user->setSetting(User::SETTING_CALENDAR_PANE_OPEN, false);

        self::assertSame(CalendarPaneMode::Mail, $user->calendarPaneMode);

        $user->calendarPaneMode = CalendarPaneMode::Calendar;

        self::assertSame(CalendarPaneMode::Calendar, $user->calendarPaneMode, 'and a stored mode outranks both');
    }
}
