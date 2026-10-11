<?php

declare(strict_types=1);

namespace App\Tests\Entity\User;

use App\Domain\Enum\Mail\ReadingPaneMode;
use App\Entity\User\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a user's reading-pane settings read back as.
 *
 * Two claims. Nobody who has not chosen gets a different mailbox than they had
 * yesterday, and nothing a stored value can say makes the divider unreachable:
 * a width outside the range is brought back into it on the way out, because the
 * bag is JSON that a restore or an older build may have written, and a stored
 * 4000 percent must not push the list off the screen with no way back short of
 * the database.
 *
 * The expected figures are the constants' own values, written out: the range is
 * 25 to 75 and a fresh user sits at 55.
 */
final class ReadingPaneSettingsTest extends TestCase
{
    public function testNobodyWhoHasNotChosenKeepsTheOnePaneMailbox(): void
    {
        self::assertSame(ReadingPaneMode::Off, new User()->readingPaneMode);
    }

    public function testAFreshUserSitsAtFiftyFivePercent(): void
    {
        self::assertSame(55, new User()->readingPaneWidthPct);
    }

    public function testAStoredModeComesBack(): void
    {
        $user = new User();
        $user->readingPaneMode = ReadingPaneMode::Right;

        self::assertSame(ReadingPaneMode::Right, $user->readingPaneMode);
        self::assertSame('right', $user->getSetting(User::SETTING_READING_PANE_MODE));
    }

    public function testAWidthInsideTheRangeComesBackAsStored(): void
    {
        $user = new User();
        $user->setSetting(User::SETTING_READING_PANE_WIDTH, 40);

        self::assertSame(40, $user->readingPaneWidthPct);
    }

    #[DataProvider('outOfRangeWidths')]
    public function testAWidthOutsideTheRangeIsBroughtBackIntoIt(mixed $stored, int $expected): void
    {
        $user = new User();
        $user->setSetting(User::SETTING_READING_PANE_WIDTH, $stored);

        self::assertSame($expected, $user->readingPaneWidthPct);
    }

    /**
     * Presence companion to the clamp above: the ends of the range are
     * reachable, so a clamp that pulled everything to the default would pass
     * the out-of-range cases and fail these.
     */
    public function testTheTwoEndsOfTheRangeAreThemselvesAllowed(): void
    {
        $user = new User();

        $user->setSetting(User::SETTING_READING_PANE_WIDTH, 25);
        self::assertSame(25, $user->readingPaneWidthPct);

        $user->setSetting(User::SETTING_READING_PANE_WIDTH, 75);
        self::assertSame(75, $user->readingPaneWidthPct);
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function outOfRangeWidths(): iterable
    {
        yield 'one under the floor'        => [24, 25];
        yield 'zero'                       => [0, 25];
        yield 'negative'                   => [-30, 25];
        yield 'one over the ceiling'       => [76, 75];
        yield 'an absurd value'            => [4000, 75];
        yield 'a string from an old build' => ['60', 55];
        yield 'a float'                    => [60.5, 55];
        yield 'null'                       => [null, 55];
    }
}
