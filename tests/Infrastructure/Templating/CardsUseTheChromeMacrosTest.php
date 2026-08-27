<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Templating;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * No card insets its content by anything but the house figure — either side.
 *
 * `_partials/_card_chrome.html.twig` defines what a card looks like once —
 * `band()` is `px-4 py-3.5`, `body()` is `px-4 py-5`. The admin side mostly did
 * not use it: it hand-wrote `px-5 py-4`, `px-5 py-2`, `px-5 py-6`, `px-5 py-8`,
 * `px-3.5 py-3` and `px-6 py-6`, so a card's heading and the first word under it
 * started four pixels apart, and two cards on one page disagreed with each
 * other. THE OWNER REPORTED IT THREE TIMES. Each report was answered by fixing
 * the card in the screenshot, because there was nothing that could answer "and
 * which of the other forty is wrong".
 *
 * THEN IT WAS REPORTED A FOURTH TIME, about admin and settings disagreeing
 * with each other — and the reason this test could not have prevented that is
 * that it only ever looked at `templates/admin`. Settings was assumed correct
 * because settings/_card.html.twig draws from the macros, which is true of every
 * section that goes THROUGH that partial and says nothing about the ones that do
 * not. Three did not: the health feed's heading, its healthy-state pane and its
 * issue bodies, the last of which widened to px-5 only above the `@2xl`
 * container breakpoint — so it was wrong on a desktop pane and right on a narrow
 * one, which is the hardest version of this bug to see and the easiest to
 * report. So the sweep now covers BOTH trees, which is the whole of the change:
 * the rule below did not need loosening or a settings-specific variant, it
 * needed pointing at the other half of the application.
 *
 * The rule is: inside the card trees, no element sets a horizontal inset wider
 * than the macros' px-4. That is deliberately a threshold rather than a list of
 * forbidden classes. Buttons, chips, badges, table cells and inputs all pad
 * themselves and all of them sit at px-1.5 to px-4, so they are below the line by
 * construction and this test has nothing to say about them; a card inset is the
 * only thing in this codebase that ever went above it. Variant prefixes are
 * caught too — `@2xl:px-5` and `sm:px-5` read the same as a bare `px-5` here,
 * because a card that is only wrong at one width is still wrong.
 *
 * WHAT THIS RULE CANNOT SEE is an inset that is too NARROW, because there is no
 * threshold to put underneath: px-0 through px-4 are all legitimate on something
 * inside a card. The health heading's `@2xl:px-0` was exactly that — it cancelled
 * its own inset above the breakpoint and left the heading 20px left of the first
 * word beneath it. That direction is the rendered counterpart's job, which
 * measures where text actually lands instead of what class put it there; see
 * check C in tests/e2e/settings-card-insets.spec.ts.
 *
 * Static, for the reason NoInlineEventHandlersTest is static. The rendered
 * counterparts — tests/e2e/admin-card-insets.spec.ts and
 * tests/e2e/settings-card-insets.spec.ts — measure the same cards in a browser
 * and are the better bug report when one is actually broken, but they can only
 * see the state a section happens to be in: an empty-state body, a pager that
 * needs two pages of rows, a tinted card that appears only once something has
 * been handled, a health feed on an installation where nothing is wrong. Those
 * are most of the offences that existed, and none of them is on screen during an
 * ordinary run — the health issue body above is precisely a branch no passing
 * run renders. This test reads the markup, so it sees every branch whether or not
 * anything rendered it.
 *
 * Two kinds of file are skipped, both for the same reason: they contain no
 * cards.
 *
 *   - Anything that `{% extends %}` a layout is a whole page rather than a
 *     section fragment — the dashboard shell, the settings shell, the two
 *     standalone pages a reset leaves behind, and the modal forms. Both shells'
 *     `px-6` is the pane gutter, paired with a `-mx-6` on the card column that
 *     cancels it; changing it to px-4 would move the section nav, not a card.
 *     The sharing and integrations modals are pages by the same test and keep
 *     their `px-6` dialog gutter for the same reason.
 *   - Only `px-`, never `pl-`/`pr-` alone, and never the four-sided `p-`.
 *     One-sided padding is a gutter here, not an inset: the reported-mail rows
 *     use `pl-6` to line the expanded body up under its disclosure chevron,
 *     which is correct and has nothing to do with the card edge.
 */
final class CardsUseTheChromeMacrosTest extends TestCase
{
    /**
     * The widest inset the macros use, in Tailwind's spacing steps.
     *
     * Anything above this is a hand-rolled card inset — px-5 was the old admin
     * figure and accounts for nearly all of it, px-6 for the rest.
     */
    private const int HOUSE_INSET = 4;

    /**
     * The two trees that draw cards.
     *
     * Both, always, in one test rather than one test each: the report that
     * prompted this was "admin and settings disagree", and a run that names
     * every offence on both sides in one message is the one that answers it.
     * Splitting them would also make it possible to point the sweep at admin
     * again by deleting a method, which is how it came to cover one tree in the
     * first place.
     *
     * @var list<string>
     */
    private const array CARD_TREES = ['templates/admin', 'templates/settings'];

    public function testNoCardHandRollsItsInset(): void
    {
        $offences = [];

        foreach ($this->cardTemplates() as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            foreach (explode("\n", $contents) as $number => $line) {
                foreach ($this->wideInsets($line) as $class) {
                    $offences[] = sprintf(
                        '%s:%d  %s',
                        str_replace(self::projectDir() . '/', '', $file->getPathname()),
                        $number + 1,
                        $class,
                    );
                }
            }
        }

        self::assertSame(
            [],
            $offences,
            "These inset a card's content further than the card's own header does,\n"
            . "so the heading and the first word under it start at different x —\n"
            . "which is what the owner has now reported four times. The fourth was\n"
            . "about admin and settings disagreeing with each other, so BOTH trees\n"
            . "are swept here and the house figure is the same one on both sides.\n"
            . "\n"
            . "Import the macros and use them:\n"
            . "  {% import '_partials/_card_chrome.html.twig' as chrome %}\n"
            . "  <div class=\"{{ chrome.shell() }}\">          the card\n"
            . "  <div class=\"{{ chrome.band() }}\">           its header\n"
            . "  <div class=\"{{ chrome.body() }}\">           a body of bare content\n"
            . "\n"
            . "In settings the card is usually already built for you — embed it:\n"
            . "  {% embed 'settings/_card.html.twig' with { heading: … } %}\n"
            . "      {% block body %}…{% endblock %}\n"
            . "  {% endembed %}\n"
            . "\n"
            . "A body that is a LIST does not take chrome.body() — padding it and its\n"
            . "rows both puts 32px between the card edge and the first word. Give the\n"
            . "rows a plain px-4 instead, which is the same figure the band uses.\n"
            . "\n"
            . "A responsive inset (`@2xl:px-5`) counts. A card that lines up only on a\n"
            . "narrow pane is the version of this bug that survives review longest.\n",
        );
    }

    /**
     * Every horizontal inset on this line that is wider than the macros'.
     *
     * Read out of `class` attributes only. The templates discuss their own
     * padding in comments — several say in prose that they used to be px-5 —
     * and a rule that fired on the explanation of the fix would be a poor one.
     *
     * @return list<string>
     */
    private function wideInsets(string $line): array
    {
        $found = [];

        if (0 === preg_match_all('/\bclass="([^"]*)"/s', $line, $attributes)) {
            return $found;
        }

        foreach ($attributes[1] as $classes) {
            // The variant prefixes are captured with the class and reported
            // with it. `@2xl:px-5` matching and then being printed as a bare
            // `px-5` sent a reader grepping for a string that is not in the
            // file — and the responsive ones are exactly the offences that are
            // hard to find by eye, so the message has to hand over the whole
            // token. Prefixes are `word:` or `@word:`, repeatable, which covers
            // `@2xl:`, `sm:`, `dark:` and `md:hover:`.
            preg_match_all(
                '/(?<![\w-])((?:@?[\w.-]+:)*)px-(\d+(?:\.\d+)?)\b/',
                $classes,
                $insets,
                PREG_SET_ORDER,
            );

            foreach ($insets as $inset) {
                if ((float) $inset[2] > self::HOUSE_INSET) {
                    $found[] = $inset[0];
                }
            }
        }

        return $found;
    }

    /**
     * The templates that draw cards — every fragment in both trees, no pages.
     *
     * Sorted, because RecursiveDirectoryIterator returns whatever order the
     * filesystem hands back and an offence list that reshuffles between runs is
     * a poor thing to diff against a previous one.
     *
     * @return iterable<SplFileInfo>
     */
    private function cardTemplates(): iterable
    {
        foreach (self::CARD_TREES as $tree) {
            $found = [];

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::projectDir() . '/' . $tree),
            );

            foreach ($iterator as $file) {
                if (false === $file instanceof SplFileInfo || 'twig' !== $file->getExtension()) {
                    continue;
                }

                if (1 === preg_match('/\{%\s*extends\s/', (string) file_get_contents($file->getPathname()))) {
                    continue;
                }

                $found[$file->getPathname()] = $file;
            }

            // The sweep finding nothing is the failure mode this whole file
            // exists to prevent — it is how the settings half went unguarded
            // while the test still passed. A tree renamed or moved fails loudly
            // here instead of quietly checking zero files.
            self::assertNotSame(
                [],
                $found,
                sprintf('%s holds no card fragments — has the tree moved?', $tree),
            );

            ksort($found);

            yield from $found;
        }
    }

    private static function projectDir(): string
    {
        return \dirname(__DIR__, 3);
    }
}
