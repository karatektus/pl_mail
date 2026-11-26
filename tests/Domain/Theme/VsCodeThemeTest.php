<?php

declare(strict_types=1);

namespace App\Tests\Domain\Theme;

use App\Domain\Theme\Oklab;
use App\Domain\Theme\Palette;
use App\Domain\Theme\VsCodeTheme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A VS Code colour theme comes out as a whole plMail palette whose text reads
 * as well as the built-in themes' does.
 *
 * The two fixtures are the keys this reads from Tokyo Night (dark, with a
 * translucent accent and a comment in it, the way VS Code writes them) and
 * Ayu Light (light, with translucent greys for its text and borders).
 */
final class VsCodeThemeTest extends TestCase
{
    private const string TOKYO_NIGHT = <<<'JSONC'
        {
            // Tokyo Night, the keys plMail reads
            "name": "Tokyo Night",
            "type": "dark",
            "semanticClass": "https://example.com//not-a-comment",
            "colors": {
                "editor.background": "#1a1b26",
                "editor.foreground": "#a9b1d6",
                "foreground": "#787c99",
                "descriptionForeground": "#515670",
                "sideBar.background": "#16161e",
                "editorWidget.background": "#16161e",
                "editorGroup.border": "#101014",
                "list.inactiveSelectionBackground": "#1c1d29",
                "input.background": "#14141b",
                "input.border": "#0f0f14",
                "focusBorder": "#545c7e33",
                "button.background": "#3d59a1dd", /* translucent: snaps to the terminal blue */
                "editorError.foreground": "#db4b4b",
                "editorWarning.foreground": "#e0af68",
                "editorInfo.foreground": "#0da0ba",
                "terminal.ansiRed": "#f7768e",
                "terminal.ansiGreen": "#73daca",
                "terminal.ansiYellow": "#e0af68",
                "terminal.ansiBlue": "#7aa2f7",
                "terminal.ansiMagenta": "#bb9af7",
                "terminal.ansiCyan": "#7dcfff",
            },
        }
        JSONC;

    private const string AYU_LIGHT = <<<'JSON'
        {
            "type": "light",
            "colors": {
                "editor.background": "#fcfcfc",
                "editor.foreground": "#020a12a3",
                "foreground": "#021a3c7a",
                "descriptionForeground": "#021a3c7a",
                "sideBar.background": "#f8f9fa",
                "editorWidget.background": "#fafafa",
                "editorGroup.border": "#6b7d8f1f",
                "list.inactiveSelectionBackground": "#6b7d8f1f",
                "input.background": "#fcfcfc",
                "input.border": "#021a3c18",
                "focusBorder": "#f29718",
                "editorError.foreground": "#e65050",
                "editorWarning.foreground": "#f29718",
                "terminal.ansiRed": "#f06b6c",
                "terminal.ansiGreen": "#6cbf43",
                "terminal.ansiYellow": "#e7a100",
                "terminal.ansiBlue": "#21a1e2",
                "terminal.ansiMagenta": "#a176cb",
                "terminal.ansiCyan": "#4abc96"
            }
        }
        JSON;

    public function testTokyoNightKeepsItsOwnSurfacesAndAccent(): void
    {
        $theme = VsCodeTheme::fromJson(self::TOKYO_NIGHT);

        self::assertNotNull($theme);
        self::assertSame('Tokyo Night', $theme->palette->name);
        self::assertTrue($theme->palette->dark);
        self::assertSame('#1a1b26', $theme->palette->colours['surface']);
        self::assertSame('#16161e', $theme->palette->colours['page']);
        self::assertSame('#7aa2f7', $theme->accent);
        self::assertSame('#7aa2f7', $theme->palette->colours['blue']);
        self::assertSame('dark', $theme->settings()['theme']);
    }

    public function testALightThemeSitsOnLight(): void
    {
        $theme = VsCodeTheme::fromJson(self::AYU_LIGHT, 'ayu-light');

        self::assertNotNull($theme);
        self::assertFalse($theme->palette->dark);
        self::assertSame('ayu-light', $theme->palette->name);
        self::assertSame('#f29718', $theme->accent);
        self::assertSame('light', $theme->settings()['theme']);
    }

    /** @return iterable<string, array{string}> */
    public static function themes(): iterable
    {
        yield 'Tokyo Night' => [self::TOKYO_NIGHT];
        yield 'Ayu Light' => [self::AYU_LIGHT];
    }

    #[DataProvider('themes')]
    public function testTextReadsOnEverySurface(string $json): void
    {
        $c = VsCodeTheme::fromJson($json)?->palette->colours ?? [];
        $grounds = ['surface' => $c['surface'], 'page' => $c['page'], 'sunken' => $c['sunken'], 'raised' => $c['raised']];

        foreach ($grounds as $ground => $colour) {
            if ('raised' !== $ground) {
                self::assertGreaterThanOrEqual(10.0, Oklab::contrast($c['ink'], $colour), 'ink on ' . $ground);
            }

            foreach (['ink', 'ink-soft', 'ink-muted', 'ink-faint', ...Palette::STATUS] as $ink) {
                self::assertGreaterThanOrEqual(4.5, Oklab::contrast($c[$ink], $colour), $ink . ' on ' . $ground);
            }
        }

        foreach (['ink', 'ink-soft', 'ink-muted', 'ink-faint'] as $ink) {
            self::assertGreaterThanOrEqual(4.5, Oklab::contrast($c[$ink], $c['field']), $ink . ' in a field');
        }

        self::assertGreaterThanOrEqual(4.5, Oklab::contrast($c['link'], $c['surface']), 'link on the sheet');
        self::assertGreaterThanOrEqual(3.0, Oklab::contrast($c['field-border'], $c['field']), 'field outline');
    }

    #[DataProvider('themes')]
    public function testTheInkTiersStepDown(string $json): void
    {
        $c = VsCodeTheme::fromJson($json)?->palette->colours ?? [];
        $on = static fn (string $ink): float => Oklab::contrast($c[$ink], $c['surface']);

        self::assertGreaterThan($on('ink-soft'), $on('ink'));
        self::assertGreaterThan($on('ink-muted'), $on('ink-soft'));
        self::assertGreaterThan($on('ink-faint') + 0.99, $on('ink-muted'));
    }

    #[DataProvider('themes')]
    public function testThePaletteSurvivesStorage(string $json): void
    {
        $palette = VsCodeTheme::fromJson($json)?->palette;

        self::assertNotNull($palette);
        self::assertEquals($palette, Palette::fromArray($palette->toArray()));
    }

    public function testTextReadsOnARaisedRowTheThemeChose(): void
    {
        $json = '{"type": "dark", "colors": {"editor.background": "#1e1e1e", "editor.foreground": "#d4d4d4", "list.inactiveSelectionBackground": "#3a3d41"}}';
        $c = VsCodeTheme::fromJson($json)?->palette->colours ?? [];

        self::assertSame('#3a3d41', $c['raised']);

        foreach (['ink-soft', 'ink-muted', 'ink-faint'] as $ink) {
            self::assertGreaterThanOrEqual(4.5, Oklab::contrast($c[$ink], $c['raised']), $ink . ' on raised');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableFields(): iterable
    {
        yield 'the ink' => [str_replace('"input.background": "#14141b"', '"input.background": "#c0caf5"', self::TOKYO_NIGHT)];
        yield 'only the fainter inks' => ['{"type": "light", "colors": {"editor.background": "#ffffff", "editor.foreground": "#000000", "input.background": "#777777"}}'];
    }

    #[DataProvider('unreadableFields')]
    public function testAFieldTheTextCannotBeReadOnFallsBack(string $json): void
    {
        $c = VsCodeTheme::fromJson($json)?->palette->colours ?? [];

        self::assertSame($c['sunken'], $c['field']);
    }

    /** @return iterable<string, array{string}> */
    public static function notThemes(): iterable
    {
        yield 'a plMail export' => ['{"version": 1, "theme": "dusk", "colors": {"editor.background": "#000000"}}'];
        yield 'no colours' => ['{"name": "Empty", "tokenColors": []}'];
        yield 'no editor background' => ['{"colors": {"foreground": "#ffffff"}}'];
        yield 'not JSON' => ['<html>'];
    }

    #[DataProvider('notThemes')]
    public function testAnythingElseIsNotATheme(string $json): void
    {
        self::assertNull(VsCodeTheme::fromJson($json));
    }
}
