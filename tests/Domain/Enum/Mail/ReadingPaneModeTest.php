<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Mail;

use App\Domain\Enum\Mail\ReadingPaneMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a stored reading-pane mode is read as.
 *
 * The settings bag is untyped JSON, so the value that comes back may be one an
 * older build wrote, one a config restore carried over, or nothing at all. The
 * claim here is that every one of those opens the mail the way it always
 * opened — one pane at a time — rather than failing to render the mailbox.
 */
final class ReadingPaneModeTest extends TestCase
{
    public function testNobodyWhoHasNotChosenKeepsTheOnePaneMailbox(): void
    {
        self::assertSame(ReadingPaneMode::Off, ReadingPaneMode::fromSetting(null));
    }

    public function testBothStoredValuesComeBackAsThemselves(): void
    {
        self::assertSame(ReadingPaneMode::Off, ReadingPaneMode::fromSetting('off'));
        self::assertSame(ReadingPaneMode::Right, ReadingPaneMode::fromSetting('right'));
    }

    #[DataProvider('unreadableValues')]
    public function testAValueThatIsNotAModeIsTheDefaultRatherThanAnError(mixed $stored): void
    {
        self::assertSame(ReadingPaneMode::Off, ReadingPaneMode::fromSetting($stored));
    }

    public function testTheCallerChoosesWhatUnreadableMeans(): void
    {
        self::assertSame(
            ReadingPaneMode::Right,
            ReadingPaneMode::fromSetting('bottom', ReadingPaneMode::Right),
        );
    }

    public function testOnlyRightPutsTheMessageBesideTheList(): void
    {
        self::assertTrue(ReadingPaneMode::Right->besideList());
        self::assertFalse(ReadingPaneMode::Off->besideList());
    }

    /** @return iterable<string, array{mixed}> */
    public static function unreadableValues(): iterable
    {
        yield 'a mode that does not exist'   => ['bottom'];
        yield 'the wrong case'               => ['Right'];
        yield 'an empty string'              => [''];
        yield 'a boolean left by a toggle'   => [true];
        yield 'a number'                     => [1];
        yield 'an array'                     => [['right']];
    }
}
