<?php

declare(strict_types=1);

namespace App\Tests\Entity\User;

use App\Domain\Enum\Mail\SidebarRow;
use App\Entity\User\User;
use PHPUnit\Framework\TestCase;

/**
 * Which sidebar rows a person keeps behind "More".
 *
 * Three states the bag can be in and they must stay three: nothing stored is
 * the default, a stored list is the choice, and a stored EMPTY list is a
 * choice too — "nothing behind More" — which a reader that treated empty as
 * absent would quietly turn back into the default.
 */
final class SidebarMoreRowsTest extends TestCase
{
    public function testNobodyWhoHasNotChosenKeepsTheDefaultBehindMore(): void
    {
        self::assertSame(SidebarRow::defaultBehindMore(), new User()->sidebarMoreRows);
    }

    /** Stored as the row values, in the order the sidebar draws them. */
    public function testAChoiceIsReadBackAsStored(): void
    {
        $user = new User();
        $user->setSetting(User::SETTING_SIDEBAR_MORE, ['sent', 'trash']);

        self::assertSame([SidebarRow::Sent, SidebarRow::Trash], $user->sidebarMoreRows);
    }

    /**
     * The vacuum check. An empty list and an absent key produce different
     * answers, and it is the empty one that is somebody's decision.
     */
    public function testAnEmptyListMeansNothingBehindMoreNotTheDefault(): void
    {
        $user = new User();
        $user->setSetting(User::SETTING_SIDEBAR_MORE, []);

        self::assertSame([], $user->sidebarMoreRows);
        self::assertNotSame(new User()->sidebarMoreRows, $user->sidebarMoreRows);
    }

    /**
     * A key the settings bag holds is written by whatever version of the app
     * was running, and read by this one. Garbage is dropped rather than thrown
     * on — a bad value must not take the whole sidebar down with it.
     *
     * Hand-derived: of the seven entries, 'nope' and 5 are not rows, 'inbox'
     * is pinned, and the second 'sent' is a repeat. What survives is trash,
     * sent and starred, and the order is the sidebar's, not the list's:
     * starred, sent, trash.
     */
    public function testWhatIsNotACleanChoiceIsDropped(): void
    {
        $user = new User();
        $user->setSetting(User::SETTING_SIDEBAR_MORE, ['trash', 'nope', 'sent', 5, 'inbox', 'sent', 'starred']);

        self::assertSame([SidebarRow::Starred, SidebarRow::Sent, SidebarRow::Trash], $user->sidebarMoreRows);
    }

    /** Not a list at all: the default, as for a key never written. */
    public function testAStoredValueThatIsNotAListFallsBackToTheDefault(): void
    {
        $user = new User();
        $user->setSetting(User::SETTING_SIDEBAR_MORE, 'archive');

        self::assertSame(SidebarRow::defaultBehindMore(), $user->sidebarMoreRows);

        // setSetting() stores a null as a PRESENT key, so null must read as
        // absent too — it is how a cleared preference is written elsewhere.
        $user->setSetting(User::SETTING_SIDEBAR_MORE, null);

        self::assertSame(SidebarRow::defaultBehindMore(), $user->sidebarMoreRows);
    }

    /**
     * Written in the sidebar's order with repeats and the pinned row removed,
     * as plain strings: the bag is JSON, and an enum case is not a value a
     * backup can carry.
     *
     * Hand-derived: Trash, Sent, Sent, Inbox → Sent, Trash.
     */
    public function testTheChoiceIsStoredCleanAsStrings(): void
    {
        $user = new User();
        $user->setSidebarMoreRows([SidebarRow::Trash, SidebarRow::Sent, SidebarRow::Sent, SidebarRow::Inbox]);

        self::assertSame(['sent', 'trash'], $user->getSetting(User::SETTING_SIDEBAR_MORE));
        self::assertSame([SidebarRow::Sent, SidebarRow::Trash], $user->sidebarMoreRows);
    }

    public function testChoosingNothingStoresAnEmptyListRatherThanClearingTheKey(): void
    {
        $user = new User();
        $user->setSidebarMoreRows([]);

        self::assertSame([], $user->getSetting(User::SETTING_SIDEBAR_MORE));
        self::assertSame([], $user->sidebarMoreRows);
    }
}
