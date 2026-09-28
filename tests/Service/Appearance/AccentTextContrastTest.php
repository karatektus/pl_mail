<?php

declare(strict_types=1);

namespace App\Tests\Service\Appearance;

use App\Domain\Enum\Theme\Theme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whatever accent a user picks, text drawn in it is readable.
 *
 * The companion to AccentInkContrastTest, which holds the ink drawn ON the
 * accent. This holds the accent used AS ink: the open folder in the sidebar,
 * the list tab you are on, a pressed chip — `text-accent`, around 115 times in
 * the templates. It had never been measured, and Dark's green was 2.95:1 on
 * its own surface, deep on purpose so the light ink on its buttons reads.
 *
 * The stylesheet does not use the accent for text any more; it derives the
 * text from it ("The accent, as text" in app.css). On a light scheme the
 * accent is mixed toward black in oklab until its lightness is at most a
 * ceiling, on a dark one toward white until it is at least a floor. This reads
 * the two bounds out of the stylesheet, repeats the derivation — written out
 * here rather than shared, for the reason AccentInkContrastTest gives — and
 * walks the RGB cube against the backgrounds accent text is really drawn on.
 *
 * Paper and Dark, the two themes held to every floor in ThemeInkContrastTest.
 */
final class AccentTextContrastTest extends TestCase
{
    private const float AA_BODY_TEXT = 4.5;

    /** bg-accent-soft: the open folder's pill, which in the Flat layout sits on the page. */
    private const float OPEN_FOLDER_WASH = 0.10;

    /** The heaviest wash of the accent under accent text: the admin badge and the address chips. */
    private const float HEAVIEST_WASH = 0.15;

    /** @return iterable<string, array{Theme}> */
    public static function themes(): iterable
    {
        yield 'paper' => [Theme::Paper];
        yield 'dark'  => [Theme::Dark];
    }

    /**
     * 18^3 accents at an even spacing. The failures a loose bound lets through
     * are saturated greens on a light scheme and magentas on a dark one, and a
     * spacing of fifteen lands on both.
     */
    #[DataProvider('themes')]
    public function testEveryAccentInTheCubeReadsAsText(Theme $theme): void
    {
        $worst = [21.0, '', ''];

        for ($r = 0; $r <= 255; $r += 15) {
            for ($g = 0; $g <= 255; $g += 15) {
                for ($b = 0; $b <= 255; $b += 15) {
                    $found = self::worstPair($theme, [$r, $g, $b]);

                    if ($found[0] < $worst[0]) {
                        $worst = $found;
                    }
                }
            }
        }

        self::assertGreaterThanOrEqual(
            self::AA_BODY_TEXT,
            $worst[0],
            sprintf('%s: the worst accent was %s at %.2f:1 on %s', $theme->value, $worst[1], $worst[0], $worst[2]),
        );
    }

    /**
     * The sweep is only worth something while text is drawn with the derived
     * colour. `text-accent` reaches it through --text-color-accent, and the
     * rules that set a colour themselves — the pressed chip, the sidebar's
     * trail, the address chips — name it too. One `color` spelled with the raw
     * accent is a way back to 2.95:1 that nothing above would notice.
     */
    public function testNothingPaintsTextInTheRawAccent(): void
    {
        $styles = dirname(__DIR__, 3) . '/assets/styles/';

        self::assertMatchesRegularExpression('/--text-color-accent\s*:\s*var\(--accent-text\)\s*;/', self::css());

        foreach (['app.css', 'tom-select.css'] as $file) {
            $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($styles . $file));

            self::assertDoesNotMatchRegularExpression(
                '/(?<![\w-])color\s*:\s*rgb\(var\(--rgb-accent\)\)/',
                $css,
                $file . ' paints text in the raw accent; use var(--accent-text).',
            );
        }
    }

    /** The accent each theme seeds, named so a regression says it was this one. */
    #[DataProvider('themes')]
    public function testTheThemesOwnAccentReadsAsText(Theme $theme): void
    {
        [$ratio, $hex, $where] = self::worstPair($theme, self::channels((string) $theme->defaults()['accent']));

        self::assertGreaterThanOrEqual(
            self::AA_BODY_TEXT,
            $ratio,
            sprintf('%s: its own accent %s is %.2f:1 on %s', $theme->value, $hex, $ratio, $where),
        );
    }

    /**
     * The derived text of $accent against every background it is drawn on,
     * returning the lowest ratio, the accent and the background.
     *
     * @param array{0:int,1:int,2:int} $accent
     *
     * @return array{0:float,1:string,2:string}
     */
    private static function worstPair(Theme $theme, array $accent): array
    {
        $palette = self::palette($theme);
        $surface = $palette['surface'];
        $text    = self::derive($accent, $theme->isDark());

        $backgrounds = [
            'the surface'          => $surface,
            'the page'             => $palette['page'],
            'its wash on the page' => self::wash($accent, self::OPEN_FOLDER_WASH, $palette['page'], $text),
            'its heaviest wash'    => self::wash($accent, self::HEAVIEST_WASH, $surface, $text),
            'a hovered row'        => self::wash($palette['raised'], $palette['hover'], $surface, $text),
        ];

        $worst = [21.0, vsprintf('#%02X%02X%02X', $accent), ''];

        foreach ($backgrounds as $where => $background) {
            $ratio = self::ratio($text, $background);

            if ($ratio < $worst[0]) {
                $worst = [$ratio, $worst[1], $where];
            }
        }

        return $worst;
    }

    /**
     * The stylesheet's derivation: the accent mixed toward black (light) or
     * white (dark) in oklab, just far enough to put its lightness past the
     * bound, then painted — clipped to sRGB and rounded to a whole step.
     *
     * @param array{0:int,1:int,2:int} $accent
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function derive(array $accent, bool $dark): array
    {
        [$l, $a, $b] = self::oklab($accent);

        if (true === $dark) {
            $floor = self::bound('--accent-text-floor');

            // Only below the floor: the stylesheet's (floor - l) / (1 - l) is
            // an infinity at white that max() swallows, and PHP throws on it.
            if ($l >= $floor) {
                return $accent;
            }

            $t = ($floor - $l) / (1 - $l);

            return self::paint($l + $t * (1 - $l), $a * (1 - $t), $b * (1 - $t));
        }

        $ceiling = self::bound('--accent-text-ceiling');

        if ($l <= $ceiling) {
            return $accent;
        }

        $k = $ceiling / $l;

        return self::paint($l * $k, $a * $k, $b * $k);
    }

    /**
     * $colour washed over $backdrop as a browser may paint it: eight-bit alpha,
     * and every channel rounded the way that brings it nearer $text — the
     * reason is in ThemeInkContrastTest::wash().
     *
     * @param array{0:int,1:int,2:int} $colour
     * @param array{0:int,1:int,2:int} $backdrop
     * @param array{0:int,1:int,2:int} $text
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function wash(array $colour, float $alpha, array $backdrop, array $text): array
    {
        $alpha8  = (int) round($alpha * 255);
        $darker  = self::luminance($text) < self::luminance($backdrop);
        $channel = static function (int $over, int $under) use ($alpha8, $darker): int {
            $exact = ($over * $alpha8 + $under * (255 - $alpha8)) / 255;

            return (int) (true === $darker ? floor($exact) : ceil($exact));
        };

        return [
            $channel($colour[0], $backdrop[0]),
            $channel($colour[1], $backdrop[1]),
            $channel($colour[2], $backdrop[2]),
        ];
    }

    /* ── Colour ───────────────────────────────────────────────────────────── */

    /**
     * sRGB to oklab, Björn Ottosson's matrices.
     *
     * @param array{0:int,1:int,2:int} $rgb
     *
     * @return array{0:float,1:float,2:float}
     */
    private static function oklab(array $rgb): array
    {
        [$r, $g, $b] = array_map(self::linear(...), $rgb);

        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    /**
     * oklab back to an sRGB colour as painted: out-of-gamut channels clipped,
     * each rounded to a whole step.
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function paint(float $l, float $a, float $b): array
    {
        $lc = ($l + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $mc = ($l - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $sc = ($l - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        $encode = static function (float $linear): int {
            $c = max(0.0, min(1.0, $linear));

            return (int) round(255 * ($c <= 0.0031308 ? 12.92 * $c : 1.055 * $c ** (1 / 2.4) - 0.055));
        };

        return [
            $encode(4.0767416621 * $lc - 3.3077115913 * $mc + 0.2309699292 * $sc),
            $encode(-1.2684380046 * $lc + 2.6097574011 * $mc - 0.3413193965 * $sc),
            $encode(-0.0041960863 * $lc - 0.7034186147 * $mc + 1.7076147010 * $sc),
        ];
    }

    private static function linear(int $channel): float
    {
        $c = $channel / 255;

        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private static function luminance(array $rgb): float
    {
        return 0.2126 * self::linear($rgb[0]) + 0.7152 * self::linear($rgb[1]) + 0.0722 * self::linear($rgb[2]);
    }

    /**
     * @param array{0:int,1:int,2:int} $a
     * @param array{0:int,1:int,2:int} $b
     */
    private static function ratio(array $a, array $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** @return array{0:int,1:int,2:int} */
    private static function channels(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /* ── Reading the stylesheet ───────────────────────────────────────────── */

    private static function bound(string $variable): float
    {
        static $bounds = [];

        if (false === isset($bounds[$variable])) {
            self::assertSame(
                1,
                preg_match(sprintf('/%s\s*:\s*([0-9.]+)\s*;/', preg_quote($variable, '/')), self::css(), $match),
                sprintf('app.css declares no %s', $variable),
            );

            $bounds[$variable] = (float) $match[1];
        }

        return $bounds[$variable];
    }

    /**
     * What the text is drawn on, out of the theme's palette block.
     *
     * @return array{surface: array{0:int,1:int,2:int}, page: array{0:int,1:int,2:int}, raised: array{0:int,1:int,2:int}, hover: float}
     */
    private static function palette(Theme $theme): array
    {
        static $palettes = [];

        if (isset($palettes[$theme->value])) {
            return $palettes[$theme->value];
        }

        $pattern = sprintf('/:root\[data-theme="%s"\]\s*\{([^}]*)\}/', preg_quote($theme->value, '/'));

        self::assertSame(1, preg_match($pattern, self::css(), $block), sprintf('app.css has no %s block', $theme->value));

        $read = static function (string $pattern) use ($block, $theme): array {
            self::assertSame(1, preg_match($pattern, $block[1], $match), sprintf('%s: nothing matches %s', $theme->value, $pattern));

            return $match;
        };

        $triple  = '\s*:\s*(\d+)\s+(\d+)\s+(\d+)\s*;/';
        $surface = $read('/--rgb-surface' . $triple);
        $raised  = $read('/--rgb-raised' . $triple);

        // The page is flat in both themes: one colour, written as a two-stop
        // gradient because .app-bg paints through background-image.
        $page = $read('/--app-bg\s*:[^;]*#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})/i');

        return $palettes[$theme->value] = [
            'surface' => [(int) $surface[1], (int) $surface[2], (int) $surface[3]],
            'page'    => [(int) hexdec($page[1]), (int) hexdec($page[2]), (int) hexdec($page[3])],
            'raised'  => [(int) $raised[1], (int) $raised[2], (int) $raised[3]],
            'hover'   => (float) $read('/--hover-alpha\s*:\s*([0-9.]+)\s*;/')[1],
        ];
    }

    private static function css(): string
    {
        static $css = null;

        return $css ??= (string) preg_replace(
            '#/\*.*?\*/#s',
            '',
            (string) file_get_contents(dirname(__DIR__, 3) . '/assets/styles/app.css'),
        );
    }
}
