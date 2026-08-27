<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Templating;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * No admin card insets its content by anything but the house figure.
 *
 * `_partials/_card_chrome.html.twig` defines what a card looks like once —
 * `band()` is `px-4 py-3.5`, `body()` is `px-4 py-5` — and every card in
 * settings uses it. The admin side mostly did not: it hand-wrote `px-5 py-4`,
 * `px-5 py-2`, `px-5 py-6`, `px-5 py-8`, `px-3.5 py-3` and `px-6 py-6`, so a
 * card's heading and the first word under it started four pixels apart, and two
 * cards on one page disagreed with each other. THE OWNER REPORTED IT THREE
 * TIMES. Each report was answered by fixing the card in the screenshot, because
 * there was nothing that could answer "and which of the other forty is wrong".
 *
 * The rule enforced here is the one that makes those forty answerable: inside
 * `templates/admin`, no element sets a horizontal inset wider than the macros'
 * px-4. That is deliberately a threshold rather than a list of forbidden
 * classes. Buttons, chips, badges, table cells and inputs all pad themselves and
 * all of them sit at px-1.5 to px-4, so they are below the line by construction
 * and this test has nothing to say about them; a card inset is the only thing in
 * this codebase that ever went above it.
 *
 * Static, for the reason NoInlineEventHandlersTest is static. The rendered
 * counterpart — tests/e2e/admin-card-insets.spec.ts — measures the same cards in
 * a browser and is the better bug report when one is actually broken, but it can
 * only see the state a section happens to be in: an empty-state body, a pager
 * that needs two pages of rows, a tinted card that appears only once something
 * has been handled. Those are most of the offences that existed, and none of
 * them is on screen during an ordinary run. This test reads the markup, so it
 * sees every branch whether or not anything rendered it.
 *
 * Two kinds of file are skipped, both for the same reason: they contain no
 * cards.
 *
 *   - Anything that `{% extends %}` a layout is a whole page rather than a
 *     section fragment — the dashboard shell, the two standalone pages a reset
 *     leaves behind, and the modal forms. The shell's `px-6` is the pane gutter,
 *     paired with a `-mx-6` on the card column that cancels it; changing it to
 *     px-4 would move the section nav, not a card.
 *   - Only `px-`, never `pl-`/`pr-` alone, and never the four-sided `p-`.
 *     One-sided padding is a gutter here, not an inset: the reported-mail rows
 *     use `pl-6` to line the expanded body up under its disclosure chevron,
 *     which is correct and has nothing to do with the card edge.
 */
final class AdminCardsUseTheChromeMacrosTest extends TestCase
{
    /**
     * The widest inset the macros use, in Tailwind's spacing steps.
     *
     * Anything above this is a hand-rolled card inset — px-5 was the old admin
     * figure and accounts for nearly all of it, px-6 for the rest.
     */
    private const int HOUSE_INSET = 4;

    public function testNoAdminCardHandRollsItsInset(): void
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
            . "which is what the owner has reported three times.\n"
            . "\n"
            . "Import the macros and use them:\n"
            . "  {% import '_partials/_card_chrome.html.twig' as chrome %}\n"
            . "  <div class=\"{{ chrome.shell() }}\">          the card\n"
            . "  <div class=\"{{ chrome.band() }}\">           its header\n"
            . "  <div class=\"{{ chrome.body() }}\">           a body of bare content\n"
            . "\n"
            . "A body that is a LIST does not take chrome.body() — padding it and its\n"
            . "rows both puts 32px between the card edge and the first word. Give the\n"
            . "rows a plain px-4 instead, which is the same figure the band uses.\n",
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
            preg_match_all('/(?<![\w-])px-(\d+(?:\.\d+)?)\b/', $classes, $insets, PREG_SET_ORDER);

            foreach ($insets as $inset) {
                if ((float) $inset[1] > self::HOUSE_INSET) {
                    $found[] = $inset[0];
                }
            }
        }

        return $found;
    }

    /**
     * The admin templates that draw cards — every fragment, no pages.
     *
     * @return iterable<SplFileInfo>
     */
    private function cardTemplates(): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::projectDir() . '/templates/admin'),
        );

        foreach ($iterator as $file) {
            if (false === $file instanceof SplFileInfo || 'twig' !== $file->getExtension()) {
                continue;
            }

            if (1 === preg_match('/\{%\s*extends\s/', (string) file_get_contents($file->getPathname()))) {
                continue;
            }

            yield $file;
        }
    }

    private static function projectDir(): string
    {
        return \dirname(__DIR__, 3);
    }
}
