<?php

declare(strict_types=1);

namespace App\Tests\Service\Appearance;

use App\Domain\Enum\Theme\Theme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every theme defines every variable, and nothing defines a variable nobody
 * reads.
 *
 * A theme is switched by rewriting `data-theme` on <html>, which means the old
 * theme's block stops matching in one step. Anything it declared and the new
 * one does not therefore falls back — to :root, or to .dark, or to whatever an
 * inline style left behind — and the user sees a colour they did not choose
 * sitting in a theme that never asked for it. That is not a hypothetical: nord
 * shipped four channels and inherited thirty-four, and two of the inherited
 * ones were visibly wrong for it (a light-theme link blue for --rgb-info, and
 * an --rgb-ink-soft darker than its own --rgb-ink-muted, so the ink ramp ran
 * backwards). Nobody chose either. They were what was left over.
 *
 * So the rule is that a palette block is complete and self-contained, and this
 * is the test that keeps it that way. It reads the stylesheet rather than a
 * list someone maintains beside it, because a list beside it is the thing that
 * goes stale.
 *
 * The inventory below is deliberately spelled out rather than derived. Adding
 * a channel should be a decision made once and then made in six places, and a
 * derived inventory would let the sixth be forgotten silently — the union
 * would simply shrink to whatever everyone happened to agree on.
 */
final class ThemeVariableCompletenessTest extends TestCase
{
    /**
     * The palette. Every block below declares all of it.
     *
     * @var list<string>
     */
    private const array INVENTORY = [
        // Surfaces
        '--rgb-surface',
        '--rgb-border',
        '--rgb-line',
        '--rgb-raised',
        '--rgb-sunken',
        '--rgb-shadow',
        // Ink
        '--rgb-ink',
        '--rgb-ink-soft',
        '--rgb-ink-muted',
        '--rgb-ink-faint',
        // Accent
        '--rgb-accent',
        '--rgb-accent-ink',
        // Fields
        '--rgb-field',
        '--rgb-field-border',
        // Status
        '--rgb-danger',
        '--rgb-warning',
        '--rgb-success',
        '--rgb-info',
        // Inverse
        '--rgb-inverse',
        '--rgb-inverse-ink',
        // Alphas
        '--border-alpha',
        '--line-alpha',
        '--raised-alpha',
        '--hover-alpha',
        '--shadow-alpha',
        '--sunken-alpha',
        '--field-alpha',
        '--field-border-alpha',
        '--danger-soft-alpha',
        '--info-soft-alpha',
        // Page
        '--app-bg',
        // The reading sheet
        '--rgb-sheet',
        '--rgb-sheet-ink',
        '--rgb-sheet-ink-soft',
        '--rgb-sheet-ink-muted',
        '--rgb-sheet-ink-faint',
        '--rgb-sheet-link',
        '--rgb-sheet-danger',
    ];

    /**
     * The user's knobs, not the theme's. AppearanceRenderer writes these inline
     * on <html> from the stored settings, an inline style beats every block in
     * the stylesheet, and a theme that redeclared them would simply be
     * overruled. They live in :root and nowhere else — a [data-theme] block
     * that sets one is stating something it cannot enforce, which is worth
     * failing over.
     *
     * @var list<string>
     */
    private const array KNOBS = [
        '--rgb-main',
        '--main-alpha',
        '--pane-alpha',
        '--pane-blur',
        '--app-radius',
        '--density-row-y',
        '--density-gap',
        '--scrim-alpha',
        '--pane-header-h',
    ];

    /** Blocks that must carry the whole palette. */
    public static function paletteBlocks(): iterable
    {
        foreach (array_keys(self::blocks()) as $selector) {
            yield $selector => [$selector];
        }
    }

    #[DataProvider('paletteBlocks')]
    public function testBlockDeclaresTheWholeInventory(string $selector): void
    {
        $declared = array_keys(self::blocks()[$selector]);
        $palette  = array_values(array_diff($declared, self::KNOBS));

        sort($palette);
        $expected = self::INVENTORY;
        sort($expected);

        self::assertSame(
            $expected,
            $palette,
            sprintf(
                '%s is not a complete palette. Missing: %s. Unexpected: %s.',
                $selector,
                implode(', ', array_diff($expected, $palette)) ?: '—',
                implode(', ', array_diff($palette, $expected)) ?: '—',
            ),
        );
    }

    /**
     * Knobs belong to :root. Anywhere else they are a promise the cascade will
     * not keep, because the inline style AppearanceRenderer writes wins.
     */
    #[DataProvider('paletteBlocks')]
    public function testOnlyRootDeclaresKnobs(string $selector): void
    {
        if (':root' === $selector) {
            self::assertSame(
                self::KNOBS,
                array_values(array_intersect(array_keys(self::blocks()[$selector]), self::KNOBS)),
                ':root should declare every user knob exactly once, in order.',
            );

            return;
        }

        self::assertSame(
            [],
            array_values(array_intersect(array_keys(self::blocks()[$selector]), self::KNOBS)),
            $selector . ' declares a user knob, which AppearanceRenderer overrules inline.',
        );
    }

    /**
     * Every Theme case paints from a complete block.
     *
     * `system` and `light` have no [data-theme] block on purpose: :root is the
     * light palette and .dark is the dark one, and a copy under a selector
     * would be a second place to forget. Everything else names its own.
     */
    #[DataProvider('themes')]
    public function testEveryThemeHasACompletePalette(Theme $theme): void
    {
        $blocks   = self::blocks();
        $selector = sprintf('[data-theme="%s"]', $theme->value);

        if (Theme::System === $theme || Theme::Light === $theme) {
            self::assertArrayNotHasKey($selector, $blocks, $theme->value . ' should paint from :root/.dark.');
            self::assertArrayHasKey(':root', $blocks);
            self::assertArrayHasKey('.dark', $blocks);

            return;
        }

        self::assertArrayHasKey(
            $selector,
            $blocks,
            sprintf('Theme::%s has no palette block in app.css.', $theme->name),
        );
    }

    /**
     * No dead entries: a variable in the inventory that nothing reads is six
     * lines of maintenance for nothing. --rgb-sheet and --rgb-sheet-ink were
     * exactly that for a fortnight — declared by the reading-sheet redesign,
     * wired to nothing, while a second @utility mail-sheet further down the
     * file painted the pane from a hardcoded white.
     */
    #[DataProvider('inventory')]
    public function testEveryVariableIsRead(string $variable): void
    {
        self::assertNotSame(
            [],
            self::referencesTo($variable),
            $variable . ' is declared by every theme and read by nothing.',
        );
    }

    /**
     * One mail-sheet. Two @utility blocks of the same name do not merge — the
     * later one wins outright — so the earlier is dead code that reads as live.
     */
    public function testMailSheetIsDefinedOnce(): void
    {
        self::assertSame(
            1,
            preg_match_all('/^@utility\s+mail-sheet\s*\{/m', self::stylesheet()),
            'app.css defines @utility mail-sheet more than once; the last one silently wins.',
        );
    }

    /**
     * Picking a theme seeds the same settings whichever theme it is.
     *
     * This is the other half of the remnant fix, and the half that is not CSS.
     * Theme::defaults() is written inline on <html> when a theme is picked, so
     * a key that one theme seeds and another does not survives the switch —
     * paints over the new theme's block, and gets saved as the new theme's
     * setting. When only Paper and Dark named an accent, Paper → Nord left
     * Nord wearing Paper's clay for good.
     */
    public function testEveryThemeSeedsTheSameSettings(): void
    {
        $shape = array_keys(Theme::System->defaults());

        foreach (Theme::cases() as $theme) {
            self::assertSame(
                $shape,
                array_keys($theme->defaults()),
                sprintf('Theme::%s seeds a different set of settings than the others.', $theme->name),
            );
        }

        self::assertContains('accent', $shape);
    }

    /**
     * And the colour it seeds is the one its block paints, so the picker's
     * swatch, the saved setting and the stylesheet all say the same thing.
     */
    #[DataProvider('themes')]
    public function testSeededAccentMatchesTheBlock(Theme $theme): void
    {
        $selector = sprintf('[data-theme="%s"]', $theme->value);
        $blocks   = self::blocks();
        $block    = $blocks[$selector] ?? $blocks[':root'];

        [$r, $g, $b] = sscanf((string) $theme->defaults()['accent'], '#%2x%2x%2x');

        self::assertSame(
            sprintf('%d %d %d', $r, $g, $b),
            preg_replace('/\s+/', ' ', trim($block['--rgb-accent'])),
            sprintf('Theme::%s seeds an accent its palette block does not paint.', $theme->name),
        );
    }

    /** @return iterable<string, array{Theme}> */
    public static function themes(): iterable
    {
        foreach (Theme::cases() as $theme) {
            yield $theme->value => [$theme];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function inventory(): iterable
    {
        foreach (self::INVENTORY as $variable) {
            yield $variable => [$variable];
        }
    }

    /* ── Reading the stylesheet ───────────────────────────────────────────── */

    private static function projectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function stylesheet(): string
    {
        return (string) file_get_contents(self::projectDir() . '/assets/styles/app.css');
    }

    /**
     * The top-level palette blocks, in source order, as selector => declarations.
     *
     * Parsed rather than regex-matched per block: :root also appears inside the
     * prefers-reduced-transparency media query, and that one is a knob override
     * rather than a palette. Only depth-zero rules count, and at-rules are
     * skipped entirely.
     *
     * @return array<string, array<string, string>>
     */
    private static function blocks(): array
    {
        static $cache = null;

        if (null !== $cache) {
            return $cache;
        }

        $css    = (string) preg_replace('#/\*.*?\*/#s', '', self::stylesheet());
        $blocks = [];
        $start  = 0;
        $length = strlen($css);

        for ($i = 0; $i < $length; $i++) {
            // A statement at depth zero — @import, @variant — ends the run of
            // text that would otherwise be read as the next rule's selector.
            // Without this, :root's selector is everything from byte zero.
            if ('}' === $css[$i] || ';' === $css[$i]) {
                $start = $i + 1;

                continue;
            }

            if ('{' !== $css[$i]) {
                continue;
            }

            $selector = trim(substr($css, $start, $i - $start));
            $depth    = 0;
            $end      = $i;

            for ($j = $i; $j < $length; $j++) {
                if ('{' === $css[$j]) {
                    $depth++;
                } elseif ('}' === $css[$j]) {
                    $depth--;

                    if (0 === $depth) {
                        $end = $j;

                        break;
                    }
                }
            }

            if (self::isPaletteSelector($selector)) {
                $blocks[$selector] = self::declarations(substr($css, $i + 1, $end - $i - 1));
            }

            $i     = $end;
            $start = $end + 1;
        }

        return $cache = $blocks;
    }

    private static function isPaletteSelector(string $selector): bool
    {
        return ':root' === $selector
            || '.dark' === $selector
            || 1 === preg_match('/^\[data-theme="[a-z-]+"\]$/', $selector);
    }

    /** @return array<string, string> */
    private static function declarations(string $body): array
    {
        preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $body, $matches, PREG_SET_ORDER);

        $declarations = [];

        foreach ($matches as $match) {
            $declarations[$match[1]] = $match[2];
        }

        return $declarations;
    }

    /**
     * Where a variable is actually read — a var() reference anywhere the app
     * paints from. Declarations do not count, which is the whole point.
     *
     * @return list<string>
     */
    private static function referencesTo(string $variable): array
    {
        $needle = sprintf('var(%s)', $variable);
        $hits   = [];

        foreach (['assets', 'templates', 'src'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::projectDir() . '/' . $directory),
            );

            foreach ($iterator as $file) {
                if (false === $file->isFile()) {
                    continue;
                }

                if (false === in_array($file->getExtension(), ['css', 'js', 'twig', 'php'], true)) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                // The declaration itself reads as a reference in `--a: var(--b)`
                // only for --b, which is a genuine read. What must not count is
                // the variable's own declaration line, and that has no var().
                if (str_contains($contents, $needle)) {
                    $hits[] = $file->getPathname();
                }
            }
        }

        return $hits;
    }
}
