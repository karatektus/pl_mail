<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Hex-colour arithmetic for the logo recipes: mixing, tinting, and the WCAG
 * luminance a pastel is measured against.
 *
 * THE ROUNDING IS A CONTRACT, not a detail. The logo motifs are drawn from one
 * table — every icon × every colourway → the colour of every part — and the
 * Android build draws its launcher icons from a committed copy of that same
 * table (tests/Domain/Enum/Theme/fixtures/logo-paints.json, produced by the
 * design board's Python and now by `app:branding:export-paints`). A mix that
 * rounds half-to-even here and half-up there is a launcher icon one shade off
 * the topbar beside it, which nobody can see is wrong and nobody can explain.
 * So every step below is the board's, operation for operation:
 *
 *  - a channel is mixed as `from + (to − from) · share`, in floats;
 *  - it is rounded as floor(v + 0.5) — half up, the one rule every language
 *    spells the same way — and clamped to 0–255;
 *  - luminance is WCAG 2's relative luminance, with the 0.03928 threshold and
 *    the 2.4 exponent, summed in the same order.
 *
 * LogoMotifTest compares the whole table against the fixture, so a change to
 * any of this that moves a single channel fails there rather than on a phone.
 */
final readonly class ColourHelper
{
    private const string WHITE = '#ffffff';
    private const string BLACK = '#000000';

    /**
     * `share` of `to` mixed into `from`: 0 is `from`, 1 is `to`.
     *
     * A share a little over 1 is legal and happens — soft() steps in floats
     * and can overshoot by an ulp — which is what the clamp in hex() is for.
     */
    public static function mix(string $from, string $to, float $share): string
    {
        $a = self::channels($from);
        $b = self::channels($to);

        return self::hex([
            $a[0] + ($b[0] - $a[0]) * $share,
            $a[1] + ($b[1] - $a[1]) * $share,
            $a[2] + ($b[2] - $a[2]) * $share,
        ]);
    }

    /** Toward white. 0.87 is the board's pale ground: a wash, not a colour. */
    public static function tint(string $colour, float $share = 0.87): string
    {
        return self::mix($colour, self::WHITE, $share);
    }

    /** Toward black. */
    public static function shade(string $colour, float $share): string
    {
        return self::mix($colour, self::BLACK, $share);
    }

    /**
     * The least-white tint of a colour that is at least `target` luminance —
     * a mood behind a drawing rather than a wash over it.
     *
     * Stepped in hundredths from nothing, and the steps ACCUMULATE (`+= 0.01`)
     * rather than being computed as `i / 100`, because that is how the table
     * was made: the float drift of ninety additions decides a handful of
     * channels in it, and a cleaner loop here would disagree with the fixture
     * by one on exactly those.
     */
    public static function soft(string $colour, float $target): string
    {
        $share = 0.0;

        while ($share < 1 && self::luminance(self::mix($colour, self::WHITE, $share)) < $target) {
            $share += 0.01;
        }

        return self::mix($colour, self::WHITE, $share);
    }

    /** WCAG 2 relative luminance, 0 for black to 1 for white. */
    public static function luminance(string $colour): float
    {
        $linear = array_map(
            static function (int $channel): float {
                $value = $channel / 255;

                return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            self::channels($colour),
        );

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /** @return array{int, int, int} */
    private static function channels(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }

    /** @param array{float|int, float|int, float|int} $channels */
    private static function hex(array $channels): string
    {
        return '#' . implode('', array_map(
            static fn (float|int $value): string => sprintf('%02x', (int) max(0, min(255, floor($value + 0.5)))),
            $channels,
        ));
    }
}
