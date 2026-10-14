<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Mail;

use App\Domain\Enum\Mail\SheetEntry;
use App\Domain\Enum\Mail\SwipeAction;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The long-press sheet's entries that are outside the swipe set (E1 in the
 * touch-gesture plan), and what each one says about itself.
 *
 * The case set is the claim to guard. Trash, restore, purge, snooze, move,
 * labels, spam and select are the sheet's own; read/unread, archive and star are
 * the swipe's, and a sheet entry must never share a value with one of them, or
 * the sheet would have two answers for one action. Trash is the case that matters
 * most: it lives here and never in SwipeAction (D5), so a swipe cannot commit a
 * delete with no tap in front of it.
 *
 * Each rule is a match table with no default, so a case without a mapping is an
 * error at the match. The tests below name the mapping they expect for every case.
 */
final class SheetEntryTest extends KernelTestCase
{
    /** The locales the shipped catalogue carries; a key missing from one is a gap a user would see. */
    private const array LOCALES = ['de', 'en', 'en_PI', 'fr'];

    public function testTheCaseSetIsExactlyTheEightEntriesOutsideTheSwipeSet(): void
    {
        $values = array_map(static fn (SheetEntry $entry): string => $entry->value, SheetEntry::cases());

        self::assertSame(['trash', 'restore', 'purge', 'snooze', 'move', 'labels', 'spam', 'select'], $values);

        foreach ($values as $value) {
            self::assertNull(SwipeAction::tryFrom($value), sprintf('"%s" must not be a swipe action too', $value));
        }
    }

    public function testTrashIsASheetEntryAndNeverASwipeAction(): void
    {
        self::assertSame(SheetEntry::Trash, SheetEntry::from('trash'), 'presence companion: trash is in the sheet');
        self::assertNull(SwipeAction::tryFrom('trash'), 'absence: trash is not a swipe action');
    }

    public function testRoutedEntriesNameARouteTheRouterKnows(): void
    {
        $routes = self::getContainer()->get('router')->getRouteCollection();

        foreach (SheetEntry::cases() as $entry) {
            if (null === $entry->route()) {
                continue;
            }

            self::assertNotNull($routes->get($entry->route()), sprintf('"%s" names route "%s", which the router does not have', $entry->value, $entry->route()));
        }
    }

    public function testTheClientOnlyEntriesHaveNoRouteAndRefuseToBePosted(): void
    {
        foreach ([SheetEntry::Move, SheetEntry::Labels, SheetEntry::Select] as $entry) {
            self::assertNull($entry->route(), $entry->value . ' has no route');

            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('"' . $entry->value . '"');

            $entry->requireRoute();
        }
    }

    public function testAPostingEntryReturnsItsRouteFromRequireRoute(): void
    {
        self::assertSame('app_status_trash', SheetEntry::Trash->requireRoute(), 'presence companion: trash posts');
    }

    public function testOnlySpamTakesTheIdAloneBecauseItsReportRouteIsUnderTheThread(): void
    {
        self::assertFalse(SheetEntry::Spam->takesType());
        self::assertTrue(SheetEntry::Trash->takesType(), 'presence companion: trash takes the type');
    }

    /**
     * Read from the strip's conditions, so a change to one of them shows here:
     * trash, restore and purge are not withheld from drafts; move, labels, snooze
     * and spam are; select is on every row.
     */
    #[DataProvider('draftRules')]
    public function testEachEntryWithholdsItselfFromDraftsExactlyWhereTheStripDoes(SheetEntry $entry, bool $expected): void
    {
        self::assertSame($expected, $entry->needsNotADraft());
    }

    /** @return iterable<string, array{SheetEntry, bool}> */
    public static function draftRules(): iterable
    {
        yield 'trash'   => [SheetEntry::Trash, false];
        yield 'restore' => [SheetEntry::Restore, false];
        yield 'purge'   => [SheetEntry::Purge, false];
        yield 'snooze'  => [SheetEntry::Snooze, true];
        yield 'move'    => [SheetEntry::Move, true];
        yield 'labels'  => [SheetEntry::Labels, true];
        yield 'spam'    => [SheetEntry::Spam, true];
        yield 'select'  => [SheetEntry::Select, false];
    }

    public function testEveryLabelKeyTheEnumNamesIsInEveryCatalogue(): void
    {
        foreach (SheetEntry::cases() as $entry) {
            foreach (self::LOCALES as $locale) {
                self::assertTrue(self::catalogueHas($locale, $entry->labelKey()), sprintf('"%s" is missing from messages.%s.yaml', $entry->labelKey(), $locale));
            }
        }
    }

    private static function catalogueHas(string $locale, string $key): bool
    {
        $tree = Yaml::parseFile(dirname(__DIR__, 4) . '/translations/messages.' . $locale . '.yaml');

        foreach (explode('.', $key) as $segment) {
            if (!is_array($tree) || !array_key_exists($segment, $tree)) {
                return false;
            }

            $tree = $tree[$segment];
        }

        return is_string($tree);
    }
}
