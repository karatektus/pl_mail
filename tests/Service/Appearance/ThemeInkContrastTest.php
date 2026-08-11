<?php

declare(strict_types=1);

namespace App\Tests\Service\Appearance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every tier of every theme's ink ramp is readable on its own surface, and the
 * tiers stay in order.
 *
 * Two separate promises, both of which had been broken:
 *
 *   Readable. --rgb-ink-faint was 2.56:1 on white — it is the colour the 11px
 *   labels are drawn in, and it was little more than half of what WCAG AA asks
 *   for body text. Every theme failed this at the faint tier and several at the
 *   muted one.
 *
 *   In order. Fixing the first can break the second, and did: darkening solar's
 *   faint and muted tiers pushed them PAST its own ink and ink-soft, so the
 *   ramp ran backwards and "less important" text came out stronger than the
 *   text it was subordinate to. Contrast alone is not the property wanted; a
 *   legible ordered ramp is.
 *
 * Read out of the stylesheet rather than from a table kept beside it, for the
 * reason ThemeVariableCompletenessTest gives: a table beside it goes stale.
 */
final class ThemeInkContrastTest extends TestCase
{
    /** WCAG 2.x AA for body text, which is what these tiers draw. */
    private const float AA_BODY_TEXT = 4.5;

    /**
     * Each ramp: the four ink tiers and the surface they sit on.
     *
     * @var array<string, array{list<string>, string}>
     */
    private const array RAMPS = [
        'page' => [
            ['--rgb-ink', '--rgb-ink-soft', '--rgb-ink-muted', '--rgb-ink-faint'],
            '--rgb-surface',
        ],
        'reading sheet' => [
            ['--rgb-sheet-ink', '--rgb-sheet-ink-soft', '--rgb-sheet-ink-muted', '--rgb-sheet-ink-faint'],
            '--rgb-sheet',
        ],
    ];

    /** @return iterable<string, array{string, string}> */
    public static function ramps(): iterable
    {
        foreach (array_keys(self::palettes()) as $selector) {
            foreach (array_keys(self::RAMPS) as $ramp) {
                yield $selector . ' / ' . $ramp => [$selector, $ramp];
            }
        }
    }

    #[DataProvider('ramps')]
    public function testEveryInkTierMeetsAAOnItsSurface(string $selector, string $ramp): void
    {
        [$tiers, $surfaceVar] = self::RAMPS[$ramp];

        $palette = self::palettes()[$selector];
        $surface = $palette[$surfaceVar];

        foreach ($tiers as $tier) {
            $ratio = self::ratio($palette[$tier], $surface);

            self::assertGreaterThanOrEqual(
                self::AA_BODY_TEXT,
                $ratio,
                sprintf('%s %s on %s is %.2f:1', $selector, $tier, $surfaceVar, $ratio),
            );
        }
    }

    #[DataProvider('ramps')]
    public function testTheRampRunsFromStrongestToFaintest(string $selector, string $ramp): void
    {
        [$tiers, $surfaceVar] = self::RAMPS[$ramp];

        $palette = self::palettes()[$selector];
        $surface = $palette[$surfaceVar];

        $ratios = array_map(
            static fn (string $tier): float => self::ratio($palette[$tier], $surface),
            $tiers,
        );

        for ($i = 1; $i < count($ratios); $i++) {
            self::assertLessThan(
                $ratios[$i - 1],
                $ratios[$i],
                sprintf(
                    '%s %s: %s (%.2f:1) is not fainter than %s (%.2f:1)',
                    $selector,
                    $ramp,
                    $tiers[$i],
                    $ratios[$i],
                    $tiers[$i - 1],
                    $ratios[$i - 1],
                ),
            );
        }
    }

    /**
     * The channel triples of every palette block that declares a surface.
     *
     * @return array<string, array<string, array{0:int,1:int,2:int}>>
     */
    private static function palettes(): array
    {
        static $cache = null;

        if (null !== $cache) {
            return $cache;
        }

        $css = (string) preg_replace('#/\*.*?\*/#s', '', self::stylesheet());

        preg_match_all(
            '/(:root|\.dark|\[data-theme="[a-z]+"\])\s*\{([^}]*)\}/',
            $css,
            $matches,
            PREG_SET_ORDER,
        );

        $palettes = [];

        foreach ($matches as $match) {
            preg_match_all(
                '/(--rgb-[a-z-]+):\s*(\d+)\s+(\d+)\s+(\d+)\s*;/',
                $match[2],
                $vars,
                PREG_SET_ORDER,
            );

            $palette = [];

            foreach ($vars as $var) {
                $palette[$var[1]] = [(int) $var[2], (int) $var[3], (int) $var[4]];
            }

            // Blocks that carry no surface are not palettes — utilities and
            // the like. Only a block that paints a background can be measured
            // against one.
            if (isset($palette['--rgb-surface'])) {
                $palettes[$match[1]] = $palette;
            }
        }

        self::assertNotEmpty($palettes, 'no palette blocks found in app.css');

        return $cache = $palettes;
    }

    private static function stylesheet(): string
    {
        $path = dirname(__DIR__, 3) . '/assets/styles/app.css';

        self::assertFileExists($path);

        return (string) file_get_contents($path);
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

    /** @param array{0:int,1:int,2:int} $rgb */
    private static function luminance(array $rgb): float
    {
        $expand = static function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $expand($rgb[0]) + 0.7152 * $expand($rgb[1]) + 0.0722 * $expand($rgb[2]);
    }
}
