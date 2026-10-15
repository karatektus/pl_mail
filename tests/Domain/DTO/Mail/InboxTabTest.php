<?php

declare(strict_types=1);

namespace App\Tests\Domain\DTO\Mail;

use App\Domain\DTO\Mail\InboxTab;
use App\Domain\Enum\Mail\MessageCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a tab IS, now that it is either one of the five built-ins or one the
 * reader made.
 *
 * The key is the one string every surface agrees on — the URL's `?tab=`, the
 * drop target a conversation is dragged onto, the keys the counts endpoint
 * patches the strip by — so the two properties worth pinning are that a built-in
 * key is EXACTLY what it was before this existed (nothing already bookmarked,
 * cached or patched may change), and that a custom key can never be mistaken for
 * a built-in one or for somebody else's category.
 */
final class InboxTabTest extends TestCase
{
    public function testABuiltInTabKeepsTheKeyItAlwaysHad(): void
    {
        foreach (MessageCategory::cases() as $category) {
            $tab = InboxTab::builtIn($category);

            self::assertSame($category->value, $tab->value);
            self::assertFalse($tab->isCustom());
            self::assertSame($category, $tab->builtIn);
            self::assertNull($tab->categoryId);
        }
    }

    public function testACustomTabIsKeyedByItsIdAndCarriesItsName(): void
    {
        $tab = InboxTab::custom(12, 'Shipping');

        self::assertSame('c12', $tab->value);
        self::assertTrue($tab->isCustom());
        self::assertSame(12, $tab->categoryId);
        self::assertSame('Shipping', $tab->name);
        self::assertNull($tab->builtIn);
    }

    /**
     * @param array<int,string> $namesById the reader's own categories
     */
    #[DataProvider('parseCases')]
    public function testParsing(string $key, array $namesById, ?string $expectedValue): void
    {
        self::assertSame($expectedValue, InboxTab::parse($key, $namesById)?->value);
    }

    /**
     * @return iterable<string, array{string, array<int,string>, ?string}>
     */
    public static function parseCases(): iterable
    {
        $own = [12 => 'Shipping', 40 => 'Receipts'];

        yield 'a built-in' => ['promotions', $own, 'promotions'];
        yield 'one of the reader\'s own' => ['c12', $own, 'c12'];
        yield 'another one of theirs' => ['c40', $own, 'c40'];
        // The two that matter: an id the reader does not own is somebody else's
        // category or a deleted one, and either way it is not a tab they have.
        yield 'a custom id that is not theirs' => ['c99', $own, null];
        yield 'a custom key with nothing of their own' => ['c12', [], null];
        yield 'not a tab at all' => ['quatsch', $own, null];
        yield 'empty' => ['', $own, null];
        yield 'the prefix alone' => ['c', $own, null];
        yield 'a malformed id' => ['c1x', $own, null];
        yield 'a negative id' => ['c-12', $own, null];
        yield 'a built-in name wearing the prefix' => ['cprimary', $own, null];
        yield 'the prefix in capitals' => ['C12', $own, null];
    }

    public function testTwoTabsAreTheSameTabWhenTheirKeysAre(): void
    {
        self::assertTrue(InboxTab::custom(12, 'Shipping')->is(InboxTab::custom(12, 'Renamed since')));
        self::assertFalse(InboxTab::custom(12, 'Shipping')->is(InboxTab::custom(13, 'Shipping')));
        self::assertFalse(InboxTab::custom(12, 'Shipping')->is(InboxTab::builtIn(MessageCategory::Primary)));
        self::assertTrue(InboxTab::builtIn(MessageCategory::Primary)->is(InboxTab::builtIn(MessageCategory::Primary)));
    }
}
