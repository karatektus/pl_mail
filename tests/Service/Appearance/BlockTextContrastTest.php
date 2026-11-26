<?php

declare(strict_types=1);

namespace App\Tests\Service\Appearance;

use App\Domain\Enum\Theme\Theme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whatever colour a calendar or event carries, a block's time and title read.
 *
 * A time-grid block (`block-tint`) is a 28% wash of its colour with its text
 * mixed 40% of that colour into the ink, in oklab. Colours come from CalDAV,
 * so any of the RGB cube can arrive, and an unbounded mix put COLOR:black at
 * 3.3:1 on Dark. The stylesheet bounds the mix's lightness as it does accent
 * text: at most --block-text-ceiling on a light scheme, at least
 * --block-text-floor on a dark one, and the light branch on the mail sheet.
 * This repeats that derivation (helpers written out, as AccentTextContrastTest
 * does) and walks the cube on every theme's surface and sheet, on the plain
 * wash and under a Maybe's stripes.
 */
final class BlockTextContrastTest extends TestCase
{
    private const float AA_BODY_TEXT = 4.5;

    private const float BLOCK_WASH = 0.28;

    /** --block-stripe is the colour at alpha 2e. */
    private const float MAYBE_STRIPE = 0x2E / 255;

    private const float TEXT_SHARE = 0.40;

    /** @return iterable<string, array{Theme}> */
    public static function themes(): iterable
    {
        foreach (Theme::cases() as $theme) {
            if (Theme::System !== $theme) {
                yield $theme->value => [$theme];
            }
        }
    }

    #[DataProvider('themes')]
    public function testEveryColourInTheCubeReadsOnABlock(Theme $theme): void
    {
        $palette = self::palette($theme);
        $grounds = [
            'the surface' => [$palette['surface'], $palette['ink'], $theme->isDark()],
            'the sheet'   => [$palette['sheet'], $palette['sheet-ink'], false],
        ];
        $worst = [21.0, ''];

        foreach ($grounds as $groundName => [$ground, $ink, $dark]) {
            for ($r = 0; $r <= 255; $r += 15) {
                for ($g = 0; $g <= 255; $g += 15) {
                    for ($b = 0; $b <= 255; $b += 15) {
                        $colour = [$r, $g, $b];
                        $text   = self::derive($colour, $ink, $dark);
                        $maybe  = self::wash($colour, self::maybeWash(), $ground, $text);

                        $backgrounds = [
                            'its wash'           => self::wash($colour, self::BLOCK_WASH, $ground, $text),
                            "a Maybe's stripe"   => self::wash($colour, self::MAYBE_STRIPE, $maybe, $text),
                        ];

                        foreach ($backgrounds as $where => $background) {
                            $ratio = self::ratio($text, $background);

                            if ($ratio < $worst[0]) {
                                $worst = [$ratio, sprintf('%s on %s, %s', vsprintf('#%02X%02X%02X', $colour), $where, $groundName)];
                            }
                        }
                    }
                }
            }
        }

        self::assertGreaterThanOrEqual(
            self::AA_BODY_TEXT,
            $worst[0],
            sprintf('%s: the worst block was %s at %.2f:1', $theme->value, $worst[1], $worst[0]),
        );
    }

    /**
     * A saved Maybe on the time grid carries data-tentative on the chip inside
     * the block, and the invitation's face carries it on the block itself, so
     * the lighter wash under the stripes has to reach both.
     */
    public function testAMaybeBlockTakesTheLighterWashUnderItsStripes(): void
    {
        self::maybeWash();

        $grid = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/calendar/_view_timegrid.html.twig');
        $chip = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/calendar/_event_chip.html.twig');

        self::assertMatchesRegularExpression('/class="[^"]*\bblock-tint\b[^"]*"[^>]*>\s*(\{#.*?#\}\s*)*\{% include \'calendar\/_event_chip\.html\.twig\'/s', $grid);
        self::assertStringContainsString('data-tentative', $chip);
    }

    private static function maybeWash(): float
    {
        static $wash = null;

        if (null === $wash) {
            self::assertSame(
                1,
                preg_match('/\[data-tentative\]\.block-tint\s*,\s*\.block-tint:has\(\[data-tentative\]\)\s*\{\s*--block-tint\s*:\s*color-mix\([^;]*?(\d+)%/', self::css(), $match),
                'app.css gives neither a Maybe block nor a block holding a Maybe chip its own --block-tint',
            );

            $wash = (int) $match[1] / 100;
        }

        return $wash;
    }

    /**
     * @param array{0:int,1:int,2:int} $colour
     * @param array{0:int,1:int,2:int} $ink
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function derive(array $colour, array $ink, bool $dark): array
    {
        $c = self::oklab($colour);
        $i = self::oklab($ink);

        $l = self::TEXT_SHARE * $c[0] + (1 - self::TEXT_SHARE) * $i[0];
        $a = self::TEXT_SHARE * $c[1] + (1 - self::TEXT_SHARE) * $i[1];
        $b = self::TEXT_SHARE * $c[2] + (1 - self::TEXT_SHARE) * $i[2];

        if (true === $dark) {
            $floor = self::bound('--block-text-floor');

            if ($l >= $floor) {
                return self::paint($l, $a, $b);
            }

            $t = ($floor - $l) / (1 - $l);

            return self::paint($l + $t * (1 - $l), $a * (1 - $t), $b * (1 - $t));
        }

        $ceiling = self::bound('--block-text-ceiling');

        if ($l <= $ceiling) {
            return self::paint($l, $a, $b);
        }

        $k = $ceiling / $l;

        return self::paint($l * $k, $a * $k, $b * $k);
    }

    /**
     * $colour washed over $backdrop as a browser may paint it: eight-bit
     * alpha, each channel rounded toward $text (see ThemeInkContrastTest).
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

    /**
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

    /** @return array{0:int,1:int,2:int} */
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
     * @return array{surface: array{0:int,1:int,2:int}, ink: array{0:int,1:int,2:int}, sheet: array{0:int,1:int,2:int}, sheet-ink: array{0:int,1:int,2:int}}
     */
    private static function palette(Theme $theme): array
    {
        $selector = Theme::Light === $theme ? ':root' : sprintf(':root\[data-theme="%s"\]', preg_quote($theme->value, '/'));

        self::assertSame(1, preg_match('/' . $selector . '\s*\{([^}]*)\}/', self::css(), $block), sprintf('app.css has no %s block', $theme->value));

        $read = static function (string $channel) use ($block, $theme): array {
            $pattern = sprintf('/%s\s*:\s*(\d+)\s+(\d+)\s+(\d+)\s*;/', preg_quote($channel, '/'));

            self::assertSame(1, preg_match($pattern, $block[1], $match), sprintf('%s: no %s', $theme->value, $channel));

            return [(int) $match[1], (int) $match[2], (int) $match[3]];
        };

        return [
            'surface'   => $read('--rgb-surface'),
            'ink'       => $read('--rgb-ink'),
            'sheet'     => $read('--rgb-sheet'),
            'sheet-ink' => $read('--rgb-sheet-ink'),
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
