<?php

declare(strict_types=1);

namespace App\Tests\Entity\User;

use App\Domain\Enum\Calendar\CalendarSwitchSkip;
use App\Entity\User\User;
use PHPUnit\Framework\TestCase;

/**
 * The two calendar-button switches (Settings → Calendar → Behaviour) and the
 * one answer the rest of the app reads from them.
 */
final class CalendarSwitchSkipTest extends TestCase
{
    /** Off for everybody, and nothing stored for the default, like every setting in the bag. */
    public function testTheButtonCyclesUntilSomebodyChangesIt(): void
    {
        $user = new User();

        self::assertFalse($user->calendarSkipSplit);
        self::assertFalse($user->calendarSplitOnly);
        self::assertSame(CalendarSwitchSkip::None, $user->calendarSwitchSkip);
        self::assertNull($user->getSetting(User::SETTING_CALENDAR_SKIP_SPLIT));
        self::assertNull($user->getSetting(User::SETTING_CALENDAR_SPLIT_ONLY));
    }

    public function testEachSwitchOnIsItsOwnAnswerAndOffStoresNothing(): void
    {
        $user = new User();

        $user->calendarSkipSplit = true;
        self::assertSame(CalendarSwitchSkip::Split, $user->calendarSwitchSkip);
        self::assertTrue($user->getSetting(User::SETTING_CALENDAR_SKIP_SPLIT));

        $user->calendarSkipSplit = false;
        self::assertSame(CalendarSwitchSkip::None, $user->calendarSwitchSkip);
        self::assertNull($user->getSetting(User::SETTING_CALENDAR_SKIP_SPLIT), 'switching off again leaves the default, not a stored false');

        $user->calendarSplitOnly = true;
        self::assertSame(CalendarSwitchSkip::Calendar, $user->calendarSwitchSkip);
        self::assertTrue($user->getSetting(User::SETTING_CALENDAR_SPLIT_ONLY));

        $user->calendarSplitOnly = false;
        self::assertSame(CalendarSwitchSkip::None, $user->calendarSwitchSkip);
        self::assertNull($user->getSetting(User::SETTING_CALENDAR_SPLIT_ONLY));
    }

    /** With both on the button would have nowhere to go, so the one switched on last wins. */
    public function testTurningOneOnTurnsTheOtherOff(): void
    {
        $user = new User();

        $user->calendarSkipSplit = true;
        $user->calendarSplitOnly = true;

        self::assertTrue($user->calendarSplitOnly);
        self::assertFalse($user->calendarSkipSplit, 'switching split-only on took skip-split off');
        self::assertSame(CalendarSwitchSkip::Calendar, $user->calendarSwitchSkip);

        $user->calendarSkipSplit = true;

        self::assertTrue($user->calendarSkipSplit);
        self::assertFalse($user->calendarSplitOnly, 'and the other way round');
        self::assertSame(CalendarSwitchSkip::Split, $user->calendarSwitchSkip);
    }

    /** Turning a switch OFF must not touch the other one. */
    public function testTurningOneOffLeavesTheOtherAlone(): void
    {
        $user                    = new User();
        $user->calendarSplitOnly = true;

        $user->calendarSkipSplit = false;

        self::assertTrue($user->calendarSplitOnly);
        self::assertSame(CalendarSwitchSkip::Calendar, $user->calendarSwitchSkip);
    }

    /**
     * A bag with both stored — a backup edited by hand — is not a state the
     * setters can produce, and it reads as the default rather than as a guess
     * at which of the two was meant.
     */
    public function testBothStoredReadsAsTheDefaultCycle(): void
    {
        $user = new User();
        $user->setSetting(User::SETTING_CALENDAR_SKIP_SPLIT, true);
        $user->setSetting(User::SETTING_CALENDAR_SPLIT_ONLY, true);

        self::assertSame(CalendarSwitchSkip::None, $user->calendarSwitchSkip);
    }
}
