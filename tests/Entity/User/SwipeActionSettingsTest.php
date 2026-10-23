<?php

declare(strict_types=1);

namespace App\Tests\Entity\User;

use App\Domain\Enum\Mail\SwipeAction;
use App\Entity\User\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a user's per-direction swipe settings read back as.
 *
 * Each direction has its own stored choice, and a stored value that is not a
 * swipe action falls back to that direction's own default, so an invalid left
 * never changes the right and the reverse. The expected defaults are written
 * out literally: right = read/unread, left = archive.
 */
final class SwipeActionSettingsTest extends TestCase
{
    public function testNobodyWhoHasNotChosenKeepsTheDefaultsPerDirection(): void
    {
        $user = new User();

        self::assertSame(SwipeAction::ReadUnread, $user->swipeRightAction);
        self::assertSame(SwipeAction::Archive, $user->swipeLeftAction);
    }

    public function testAStoredActionComesBackForItsOwnDirectionOnly(): void
    {
        $user = new User();

        $user->swipeLeftAction = SwipeAction::Star;

        self::assertSame(SwipeAction::Star, $user->swipeLeftAction);
        self::assertSame(SwipeAction::ReadUnread, $user->swipeRightAction, 'the other direction keeps its default');
    }

    public function testTheSetterWritesTheValueUnderItsOwnKey(): void
    {
        $user = new User();

        $user->swipeRightAction = SwipeAction::None;

        self::assertSame('none', $user->getSetting(User::SETTING_SWIPE_RIGHT_ACTION));
        self::assertNull($user->getSetting(User::SETTING_SWIPE_LEFT_ACTION), 'the left key is untouched');
    }

    public function testAnInvalidLeftValueFallsBackToArchiveAndLeavesRightAlone(): void
    {
        $user = new User();
        $user->setSetting(User::SETTING_SWIPE_RIGHT_ACTION, 'star');
        $user->setSetting(User::SETTING_SWIPE_LEFT_ACTION, 'trash');

        self::assertSame(SwipeAction::Archive, $user->swipeLeftAction);
        self::assertSame(SwipeAction::Star, $user->swipeRightAction, 'presence companion: the valid right value still comes back');
    }

    public function testAnInvalidRightValueFallsBackToReadUnreadAndLeavesLeftAlone(): void
    {
        $user = new User();
        $user->setSetting(User::SETTING_SWIPE_LEFT_ACTION, 'star');
        $user->setSetting(User::SETTING_SWIPE_RIGHT_ACTION, 'trash');

        self::assertSame(SwipeAction::ReadUnread, $user->swipeRightAction);
        self::assertSame(SwipeAction::Star, $user->swipeLeftAction, 'presence companion: the valid left value still comes back');
    }

    #[DataProvider('unreadableStoredValues')]
    public function testAnyUnreadableStoredValueIsTheDirectionsDefault(mixed $stored): void
    {
        $user = new User();
        $user->setSetting(User::SETTING_SWIPE_RIGHT_ACTION, $stored);
        $user->setSetting(User::SETTING_SWIPE_LEFT_ACTION, $stored);

        self::assertSame(SwipeAction::ReadUnread, $user->swipeRightAction);
        self::assertSame(SwipeAction::Archive, $user->swipeLeftAction);
    }

    /** @return iterable<string, array{mixed}> */
    public static function unreadableStoredValues(): iterable
    {
        yield 'trash, which is not a swipe action' => ['trash'];
        yield 'an action that does not exist'      => ['bounce'];
        yield 'a number'                           => [2];
    }
}
