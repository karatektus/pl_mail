<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Mail;

use App\Domain\Enum\Mail\SwipeAction;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * What a row swipe can do, and what each action says about itself.
 *
 * The case set is the claim most worth guarding. Trash is not a swipe action
 * (D5 in the touch-gesture plan): there is no Undo for a deleted row, and a
 * full swipe with no tap in front of it is the one place a delete could happen
 * by accident. So the set below is exact, and adding a case fails here until
 * whoever adds it has reopened that decision.
 *
 * Each rule is a match table with no default, so a case without a mapping is an
 * error at the match, and the tests below name the mapping they expect so a
 * wrong one is reported by its row rather than by a screenshot.
 */
final class SwipeActionTest extends KernelTestCase
{
    /** The locales the shipped catalogue carries; a key missing from one is a gap a user would see. */
    private const array LOCALES = ['de', 'en', 'en_PI', 'fr', 'zh_CN'];

    public function testTheCaseSetIsExactlyTheFourTheDesignAllows(): void
    {
        $names = array_map(static fn (SwipeAction $case): string => $case->name, SwipeAction::cases());

        // Presence first: a set compared on its own could pass for an empty enum.
        self::assertContains('ReadUnread', $names);
        self::assertSame(['ReadUnread', 'Archive', 'Star', 'None'], $names);
    }

    public function testReadUnreadArchiveAndStarHaveRoutesAndNoneHasNone(): void
    {
        self::assertSame('app_status_mark_read', SwipeAction::ReadUnread->route());
        self::assertSame('app_status_archive', SwipeAction::Archive->route());
        self::assertSame('app_status_star', SwipeAction::Star->route());
        self::assertNull(SwipeAction::None->route());
    }

    public function testEveryRouteNamedByAnActionExistsInTheRouter(): void
    {
        self::bootKernel();

        $collection = self::getContainer()->get('router')->getRouteCollection();

        foreach (SwipeAction::cases() as $action) {
            $route = $action->route();

            if (null === $route) {
                continue;
            }

            self::assertNotNull($collection->get($route), sprintf('"%s" names route "%s", which the router does not have', $action->name, $route));
        }
    }

    public function testMarkReadAndStarToggleWhileArchiveDoesNot(): void
    {
        self::assertTrue(SwipeAction::ReadUnread->toggles(), 'mark read and mark unread are one toggle');
        self::assertTrue(SwipeAction::Star->toggles(), 'the star route toggles on the server');
        self::assertFalse(SwipeAction::Archive->toggles(), 'archive has an Undo, not a second swipe');
        self::assertFalse(SwipeAction::None->toggles());
    }

    #[DataProvider('labelCases')]
    public function testTheLabelFollowsTheStateTheActionWouldChange(string $action, bool $unread, bool $starred, ?string $expected): void
    {
        self::assertSame($expected, SwipeAction::from($action)->labelKey($unread, $starred));
    }

    /** @return iterable<string, array{string, bool, bool, ?string}> */
    public static function labelCases(): iterable
    {
        yield 'an unread row offers to mark it read' => ['read_unread', true, false, 'thread_row.actions.mark_read'];
        yield 'a read row offers to mark it unread' => ['read_unread', false, false, 'thread_row.actions.mark_unread'];
        yield 'an unstarred row offers to star it' => ['star', false, false, 'thread_row.star'];
        yield 'a starred row offers to unstar it' => ['star', false, true, 'thread_row.unstar'];
        yield 'archive is always archive' => ['archive', true, true, 'thread_row.actions.archive'];
        yield 'none has no label' => ['none', true, true, null];
    }

    /**
     * Presence companion for the catalogue test below: every key the enum names
     * is looked up in all four catalogues, so a key missing from one shows as
     * one failing case per locale rather than a single vague assertion.
     */
    public function testEveryLabelKeyTheEnumNamesIsInEveryCatalogue(): void
    {
        $keys = [];

        foreach (SwipeAction::cases() as $action) {
            foreach ([true, false] as $unread) {
                foreach ([true, false] as $starred) {
                    $key = $action->labelKey($unread, $starred);

                    if (null !== $key) {
                        $keys[$key] = true;
                    }
                }
            }
        }

        self::assertNotEmpty($keys, 'the enum names at least one label key');

        foreach (array_keys($keys) as $key) {
            foreach (self::LOCALES as $locale) {
                self::assertTrue(self::catalogueHas($locale, $key), sprintf('"%s" is missing from messages.%s.yaml', $key, $locale));
            }
        }
    }

    public function testEveryDoingActionHasAnIcon(): void
    {
        self::assertSame('fa-solid fa-box-archive', SwipeAction::Archive->icon(false, false));
        self::assertSame('fa-solid fa-envelope-open', SwipeAction::ReadUnread->icon(true, false));
        self::assertSame('fa-solid fa-envelope', SwipeAction::ReadUnread->icon(false, false));
        self::assertSame('fa-solid fa-star', SwipeAction::Star->icon(false, true));
        self::assertSame('fa-regular fa-star', SwipeAction::Star->icon(false, false));
        self::assertNull(SwipeAction::None->icon(false, false));
    }

    public function testMarkReadSendsTheStateItWillSet(): void
    {
        // Read = true when the row is unread, the same body the row's own
        // mark-read button posts.
        self::assertSame(['read' => true], SwipeAction::ReadUnread->requestBody(true));
        self::assertSame(['read' => false], SwipeAction::ReadUnread->requestBody(false));
        self::assertSame([], SwipeAction::Archive->requestBody(true));
        self::assertSame([], SwipeAction::Star->requestBody(true));
    }

    /**
     * The tripwire for an action with no route. None is deliberately unmapped,
     * so asking it for a route must throw and name it, and a message that says
     * only "no route" would send the reader to the wrong switch.
     */
    public function testAskingForTheRouteOfAnUnmappedActionThrowsNamingIt(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"none"');

        SwipeAction::None->requireRoute();
    }

    public function testRequireRouteReturnsTheRouteForAMappedAction(): void
    {
        self::assertSame('app_status_archive', SwipeAction::Archive->requireRoute());
    }

    /**
     * Each stored action comes back as itself. Presence companion to the
     * default cases below: without it, "every value falls back" would pass for
     * a fromSetting that ignores what it is given.
     */
    public function testEveryStoredActionComesBackAsItself(): void
    {
        self::assertSame(SwipeAction::ReadUnread, SwipeAction::fromSetting('read_unread', SwipeAction::Archive));
        self::assertSame(SwipeAction::Archive, SwipeAction::fromSetting('archive', SwipeAction::ReadUnread));
        self::assertSame(SwipeAction::Star, SwipeAction::fromSetting('star', SwipeAction::Archive));
        self::assertSame(SwipeAction::None, SwipeAction::fromSetting('none', SwipeAction::ReadUnread));
    }

    /**
     * Two baselines, one per direction, so a fallback that always answers the
     * same action fails one of them. Expected values are the literal defaults:
     * right = read/unread, left = archive.
     */
    public function testAnUnreadableValueFallsBackToTheDefaultTheCallerPassed(): void
    {
        self::assertSame(SwipeAction::ReadUnread, SwipeAction::fromSetting(null, SwipeAction::ReadUnread));
        self::assertSame(SwipeAction::Archive, SwipeAction::fromSetting(null, SwipeAction::Archive));
    }

    /**
     * The stored "trash" is the value a hand-edited bag or an imported backup
     * could carry. It must fall back, so no stored string brings a trash swipe
     * back. The Star case is the presence companion in the same test.
     */
    public function testTrashIsNotAnActionEvenWhenStored(): void
    {
        self::assertSame(SwipeAction::ReadUnread, SwipeAction::fromSetting('trash', SwipeAction::ReadUnread));
        self::assertSame(SwipeAction::Archive, SwipeAction::fromSetting('trash', SwipeAction::Archive));
        self::assertSame(SwipeAction::Star, SwipeAction::fromSetting('star', SwipeAction::Archive));
    }

    #[DataProvider('unreadableSettings')]
    public function testAValueThatIsNotAnActionIsTheDefaultRatherThanAnError(mixed $stored): void
    {
        self::assertSame(SwipeAction::Archive, SwipeAction::fromSetting($stored, SwipeAction::Archive));
        self::assertSame(SwipeAction::ReadUnread, SwipeAction::fromSetting($stored, SwipeAction::ReadUnread));
    }

    /** @return iterable<string, array{mixed}> */
    public static function unreadableSettings(): iterable
    {
        yield 'an action that does not exist' => ['bounce'];
        yield 'the wrong case'                 => ['Archive'];
        yield 'an empty string'                => [''];
        yield 'a boolean'                      => [true];
        yield 'a number'                       => [1];
        yield 'an array'                       => [['archive']];
        yield 'nothing stored'                 => [null];
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
