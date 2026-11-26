<?php

declare(strict_types=1);

namespace App\Domain\Theme;

/**
 * Colour arithmetic for turning somebody else's theme into a plMail palette.
 *
 * Mixing and lightening happen in Oklab, where equal steps look equal, so a
 * text colour lifted toward white keeps its hue instead of greying out on the
 * way. Contrast is WCAG 2.x, the measure every other ink in the app is held
 * to. Colours go in and come out as lowercase `#rrggbb`.
 */
final class Oklab
{
    /** `#rgb`, `#rgba`, `#rrggbb` or `#rrggbbaa` laid over an opaque ground. */
    public static function flatten(string $colour, string $ground): string
    {
        [$rgb, $alpha] = self::parse($colour);
        [$under] = self::parse($ground);

        return self::hex(array_map(
            static fn (int $i): float => ($rgb[$i] * $alpha) + ($under[$i] * (1 - $alpha)),
            [0, 1, 2],
        ));
    }

    public static function alpha(string $colour): float
    {
        return self::parse($colour)[1];
    }

    public static function isColour(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value);
    }

    /** WCAG 2.x contrast ratio, 1 to 21. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function luminance(string $colour): float
    {
        [$r, $g, $b] = array_map(self::linear(...), self::parse($colour)[0]);

        return (0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b);
    }

    /** @return array{0: float, 1: float, 2: float} lightness, chroma, hue in degrees */
    public static function lch(string $colour): array
    {
        [$l, $a, $b] = self::toLab($colour);

        return [$l, hypot($a, $b), fmod(rad2deg(atan2($b, $a)) + 360, 360)];
    }

    /** From lightness, chroma and hue, with chroma given up until it fits sRGB. */
    public static function fromLch(float $l, float $c, float $h): string
    {
        for ($i = 0; $i < 40; ++$i) {
            $rgb = self::labToLinear($l, $c * cos(deg2rad($h)), $c * sin(deg2rad($h)));

            if (min($rgb) >= -0.001 && max($rgb) <= 1.001) {
                break;
            }

            $c *= 0.93;
        }

        return self::hex(array_map(self::encode(...), $rgb));
    }

    /** $share of the way from $from to $to. */
    public static function mix(string $from, string $to, float $share): string
    {
        $a = self::toLab($from);
        $b = self::toLab($to);

        return self::hex(array_map(
            self::encode(...),
            self::labToLinear(...array_map(
                static fn (int $i): float => $a[$i] + (($b[$i] - $a[$i]) * $share),
                [0, 1, 2],
            )),
        ));
    }

    public static function hueGap(string $a, string $b): float
    {
        $gap = fmod(abs(self::lch($a)[2] - self::lch($b)[2]), 360);

        return min($gap, 360 - $gap);
    }

    /**
     * Mixed toward $toward in 1% steps until it reads $floor:1 on every ground.
     *
     * @param list<string> $grounds
     */
    public static function lift(string $colour, string $toward, array $grounds, float $floor): string
    {
        for ($step = 0; $step <= 100; ++$step) {
            $candidate = self::mix($colour, $toward, $step / 100);

            if (true === self::clears($candidate, $grounds, $floor)) {
                return $candidate;
            }
        }

        return self::hex(self::parse($toward)[0]);
    }

    /**
     * Only the lightness moved, away from the grounds, until it reads $floor:1
     * on every one. Hue and chroma stay, so a theme's red is still its red.
     *
     * @param list<string> $grounds
     */
    public static function liftLightness(string $colour, bool $lighter, array $grounds, float $floor): string
    {
        [$l, $c, $h] = self::lch($colour);
        $candidate = self::hex(self::parse($colour)[0]);

        for ($step = 0; $step <= 100; ++$step) {
            $candidate = self::fromLch(
                true === $lighter ? min(1.0, $l + ($step / 200)) : max(0.0, $l - ($step / 200)),
                $c,
                $h,
            );

            if (true === self::clears($candidate, $grounds, $floor)) {
                break;
            }
        }

        return $candidate;
    }

    /** `r g b`, the shape every `--rgb-*` variable takes. */
    public static function channels(string $colour): string
    {
        return vsprintf('%d %d %d', self::parse($colour)[0]);
    }

    /** @param list<string> $grounds */
    private static function clears(string $colour, array $grounds, float $floor): bool
    {
        foreach ($grounds as $ground) {
            if (self::contrast($colour, $ground) < $floor) {
                return false;
            }
        }

        return true;
    }

    /** @return array{0: list<int>, 1: float} */
    private static function parse(string $colour): array
    {
        $hex = ltrim($colour, '#');

        if (strlen($hex) <= 4) {
            $hex = implode('', array_map(static fn (string $digit): string => $digit . $digit, str_split($hex)));
        }

        $rgb = array_map(static fn (string $pair): int => (int) hexdec($pair), str_split(substr($hex, 0, 6), 2));

        return [$rgb, 8 === strlen($hex) ? hexdec(substr($hex, 6, 2)) / 255 : 1.0];
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function toLab(string $colour): array
    {
        [$r, $g, $b] = array_map(self::linear(...), self::parse($colour)[0]);

        $l = self::cbrt((0.4122214708 * $r) + (0.5363325363 * $g) + (0.0514459929 * $b));
        $m = self::cbrt((0.2119034982 * $r) + (0.6806995451 * $g) + (0.1073969566 * $b));
        $s = self::cbrt((0.0883024619 * $r) + (0.2817188376 * $g) + (0.6299787005 * $b));

        return [
            (0.2104542553 * $l) + (0.7936177850 * $m) - (0.0040720468 * $s),
            (1.9779984951 * $l) - (2.4285922050 * $m) + (0.4505937099 * $s),
            (0.0259040371 * $l) + (0.7827717662 * $m) - (0.8086757660 * $s),
        ];
    }

    /** @return array{0: float, 1: float, 2: float} linear sRGB, possibly out of gamut */
    private static function labToLinear(float $lightness, float $a, float $b): array
    {
        $l = ($lightness + (0.3963377774 * $a) + (0.2158037573 * $b)) ** 3;
        $m = ($lightness - (0.1055613458 * $a) - (0.0638541728 * $b)) ** 3;
        $s = ($lightness - (0.0894841775 * $a) - (1.2914855480 * $b)) ** 3;

        return [
            (4.0767416621 * $l) - (3.3077115913 * $m) + (0.2309699292 * $s),
            (-1.2684380046 * $l) + (2.6097574011 * $m) - (0.3413193965 * $s),
            (-0.0041960863 * $l) - (0.7034186147 * $m) + (1.7076147010 * $s),
        ];
    }

    private static function linear(int $channel): float
    {
        $c = $channel / 255;

        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function encode(float $linear): float
    {
        $v = max(0.0, min(1.0, $linear));

        return 255 * ($v <= 0.0031308 ? 12.92 * $v : (1.055 * ($v ** (1 / 2.4))) - 0.055);
    }

    private static function cbrt(float $value): float
    {
        return $value < 0 ? -((-$value) ** (1 / 3)) : $value ** (1 / 3);
    }

    /** @param array<int, float|int> $rgb */
    private static function hex(array $rgb): string
    {
        return vsprintf('#%02x%02x%02x', array_map(
            static fn (float|int $v): int => (int) max(0, min(255, round($v))),
            array_values($rgb),
        ));
    }
}
