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
 * And readable where the text actually sits. The surface is not the only
 * background: in the Flat layout the sidebar and the top bar sit on the page,
 * and Paper's faint ink was 4.57:1 on its surface and 4.34:1 there. The status
 * colours are text too — a badge, an alert, a failed step — and Paper's
 * warning was 2.70:1 inside its own badge, because nothing had ever measured a
 * status colour at all. Paper and Dark were held to these two floors first,
 * while nearly every other theme failed them — the light ones still wore
 * Tailwind's 600-weight status colours, warning at 2.9:1 — and the rest were
 * brought up after. Every block is held now, so a theme added later is
 * measured from its first commit, not from the day someone lists it.
 *
 * Read out of the stylesheet rather than from a table kept beside it, for the
 * reason ThemeVariableCompletenessTest gives: a table beside it goes stale.
 */
final class ThemeInkContrastTest extends TestCase
{
    /** WCAG 2.x AA for body text, which is what these tiers draw. */
    private const float AA_BODY_TEXT = 4.5;

    /** The four roles drawn as text on a status wash or on their own. */
    private const array STATUS = ['--rgb-danger', '--rgb-warning', '--rgb-success', '--rgb-info'];

    /**
     * The heaviest wash of its own colour the app puts status text on:
     * pill-done and pill-failed. A badge or an alert washes at
     * --danger-soft-alpha (8% on a light theme, 12% on a dark one) and the
     * thread row's chips at 10%, and a lighter wash only ever reads better.
     */
    private const float HEAVIEST_STATUS_WASH = 0.12;

    /** The sheet's own four, which @utility mail-sheet re-points the roles above to. */
    private const array SHEET_STATUS = ['--rgb-sheet-danger', '--rgb-sheet-warning', '--rgb-sheet-success', '--rgb-sheet-info'];

    /**
     * The heaviest wash of its own colour the sheet puts status text on: the
     * spam warning's bg-danger/10 and the scheduled badge's bg-info/10 (the
     * sender-mismatch strip's bg-warning/10 holds only an icon). A bounce is
     * alert-danger, at the sheet's 8% --danger-soft-alpha. Success has no
     * wash in the sheet yet and is held to the same, for the chip it would
     * get.
     */
    private const float HEAVIEST_SHEET_WASH = 0.10;

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

    /** @return iterable<string, array{string}> */
    public static function everyPalette(): iterable
    {
        foreach (array_keys(self::palettes()) as $selector) {
            yield $selector => [$selector];
        }
    }

    #[DataProvider('everyPalette')]
    public function testTheInkIsReadableOnThePageAsWellAsOnThePane(string $selector): void
    {
        $palette = self::palettes()[$selector];

        foreach (self::RAMPS['page'][0] as $tier) {
            foreach (self::pageColours($selector) as $page) {
                $ratio = self::ratio($palette[$tier], $page);

                self::assertGreaterThanOrEqual(
                    self::AA_BODY_TEXT,
                    $ratio,
                    sprintf('%s %s on the page (%s) is %.2f:1', $selector, $tier, implode(' ', $page), $ratio),
                );
            }
        }
    }

    /**
     * A status colour is text wherever it appears — the word in a badge, the
     * message in an alert — so it is held to the ink's floor: on the pane, on
     * the page, and on the wash of itself it is drawn inside.
     */
    #[DataProvider('everyPalette')]
    public function testStatusColoursAreReadableAsText(string $selector): void
    {
        $palette = self::palettes()[$selector];
        $surface = $palette['--rgb-surface'];

        foreach (self::STATUS as $status) {
            $colour      = $palette[$status];
            $backgrounds = [
                'the surface'  => $surface,
                'its own wash' => self::wash($colour, self::HEAVIEST_STATUS_WASH, $surface),
            ];

            foreach (self::pageColours($selector) as $page) {
                $backgrounds['the page (' . implode(' ', $page) . ')'] = $page;
            }

            foreach ($backgrounds as $where => $background) {
                $ratio = self::ratio($colour, $background);

                self::assertGreaterThanOrEqual(
                    self::AA_BODY_TEXT,
                    $ratio,
                    sprintf('%s %s on %s is %.2f:1', $selector, $status, $where, $ratio),
                );
            }
        }
    }

    /**
     * The message body's own colours: a link in a mail, and the status text
     * inside it — a draft and the spam warning in danger, the read-receipt
     * line in warning, "Draft saved" in an inline reply in success, the
     * scheduled badge in info — which @utility mail-sheet re-points to the
     * sheet's own. They sit on the sheet, not on the surface, and nothing
     * measured them there: nord's link and danger and solar's link read about
     * 3.5:1, and a dark theme's warning, success and info, taken over from its
     * near-black surface, 1.4–2.4:1. Each status colour is held on the wash of
     * itself the sheet draws it in, too, where solar's red read 3.70:1.
     */
    #[DataProvider('everyPalette')]
    public function testTheSheetsLinkAndStatusColoursAreReadableOnTheSheet(string $selector): void
    {
        $palette = self::palettes()[$selector];
        $sheet   = $palette['--rgb-sheet'];

        $backgrounds = ['--rgb-sheet-link' => ['the sheet' => $sheet]];

        foreach (self::SHEET_STATUS as $status) {
            $backgrounds[$status] = [
                'the sheet'    => $sheet,
                'its own wash' => self::wash($palette[$status], self::HEAVIEST_SHEET_WASH, $sheet),
            ];
        }

        foreach ($backgrounds as $var => $places) {
            foreach ($places as $where => $background) {
                $ratio = self::ratio($palette[$var], $background);

                self::assertGreaterThanOrEqual(
                    self::AA_BODY_TEXT,
                    $ratio,
                    sprintf('%s %s on %s is %.2f:1', $selector, $var, $where, $ratio),
                );
            }
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

        // `[a-z-]+`, hyphen included. The pattern was `[a-z]+` for as long as
        // there were only single-word themes, and when the logo colourways
        // brought nineteen hyphenated ones — midnight-gold, charcoal-amber, …
        // — this test skipped every one of them without a word, while two of
        // them shipped a reading sheet whose ramp ran backwards.
        preg_match_all(
            '/(:root|\.dark|\[data-theme="[a-z-]+"\])\s*\{([^}]*)\}/',
            self::css(),
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

    /**
     * Every colour stop of a theme's --app-bg: the page the chrome sits on in
     * the Flat layout. A flat theme writes its one colour as a two-stop
     * gradient (see the note on Paper's), so a stop is all there is to read.
     *
     * @return list<array{0:int,1:int,2:int}>
     */
    private static function pageColours(string $selector): array
    {
        $pattern = sprintf('/%s\s*\{[^}]*?--app-bg\s*:\s*([^;]+);/', preg_quote($selector, '/'));

        self::assertSame(1, preg_match($pattern, self::css(), $match), $selector . ' declares no --app-bg');

        preg_match_all('/#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})\b/i', $match[1], $stops, PREG_SET_ORDER);

        self::assertNotEmpty($stops, $selector . '’s --app-bg has no colour stop to measure against');

        return array_map(
            static fn (array $stop): array => [(int) hexdec($stop[1]), (int) hexdec($stop[2]), (int) hexdec($stop[3])],
            $stops,
        );
    }

    /**
     * $colour washed over $backdrop at $alpha, as a browser may paint it —
     * taking the unkind side wherever painting has a choice.
     *
     * Not the exact sum. The alpha is stored in eight bits, so a 12% wash is
     * 31/255 and a shade heavier than asked for, and every channel lands on a
     * whole step, rounded one way or the other by the paint path. Values that
     * cleared the exact sum at 4.51:1 painted pill-done at 4.49:1 in Chromium,
     * which is a fail however the arithmetic came out. So the alpha is
     * quantised and each channel rounded towards the text, the direction
     * that costs contrast.
     *
     * And Chromium's software raster can land a step past even that. It
     * paints with Skia's integer SrcOver — the colour premultiplied by the
     * eight-bit alpha and rounded, the backdrop multiplied by 256 less it and
     * shifted down eight bits — which matched every one of 3,468 washes
     * headless Chromium painted, and the canvas in a desktop Chromium too,
     * and lands one step past the rounded sum on about one channel in
     * twenty. Ocean's warning read 4.50:1 on the rounded sum alone and
     * painted 4.47:1. So each channel takes whichever of the two is unkinder.
     *
     * @param array{0:int,1:int,2:int} $colour
     * @param array{0:int,1:int,2:int} $backdrop
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function wash(array $colour, float $alpha, array $backdrop): array
    {
        $alpha8  = (int) round($alpha * 255);
        $channel = static function (int $ink, int $under) use ($alpha8): int {
            $exact = ($ink * $alpha8 + $under * (255 - $alpha8)) / 255;
            $skia  = (int) round($ink * $alpha8 / 255) + (($under * (256 - $alpha8)) >> 8);

            return $ink < $under
                ? min((int) floor($exact), $skia)
                : max((int) ceil($exact), $skia);
        };

        return [
            $channel($colour[0], $backdrop[0]),
            $channel($colour[1], $backdrop[1]),
            $channel($colour[2], $backdrop[2]),
        ];
    }

    /** The stylesheet with its comments removed, so a value in prose is never read as one. */
    private static function css(): string
    {
        static $css = null;

        return $css ??= (string) preg_replace('#/\*.*?\*/#s', '', self::stylesheet());
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
