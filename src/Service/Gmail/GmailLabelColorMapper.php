<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Domain\Enum\Mail\LabelColor;

/**
 * Gmail label colours to plMail's, and back.
 *
 * Gmail is the opposite problem to Outlook. Where Graph gives twenty-five
 * named constants and no hex — leaving nothing to match on but the name —
 * Gmail gives real hex, from a closed palette of 89 values, as a
 * backgroundColor/textColor pair that must be set together.
 *
 * So this matches by colour rather than by table. Eighty-nine hand-written
 * entries would be a table nobody could review and that would be wrong the day
 * Google adds a value; hue matching is checkable by eye and degrades sensibly
 * on anything new. Greys are decided by saturation before hue is consulted at
 * all, because hue is meaningless once there is no colour left in it — and a
 * third of Gmail's palette is grey.
 *
 * Outbound pairs are chosen FROM the 89: Gmail rejects anything else outright,
 * so these are not "close enough", they are the palette entries nearest each
 * token with a text colour that stays readable on them.
 *
 * Inbound is written only onto a label that has no colour, for the same reason
 * as the Outlook mapper: 89 onto 9 is lossy, and a colour that round-trips
 * repeatedly drifts. See GraphCategoryColorMapper.
 */
final readonly class GmailLabelColorMapper
{
    /**
     * Reference hues, in degrees, for the eight chromatic tokens — the Tailwind
     * 500 shades the chips and dots actually render.
     *
     * @var array<string, float>
     */
    private const array HUES = [
        'red'    => 0.0,     // #ef4444
        'orange' => 25.0,    // #f97316
        // 45, not the 38 the amber chip actually renders at. These references
        // classify, they do not paint. Gmail's own oranges cluster at 31–33
        // and its yellows at 43–52, so a reference of 38 sits exactly on the
        // boundary and drags every Gmail orange into amber; 45 puts the split
        // at 35, between the two clusters where it belongs.
        'amber'  => 45.0,
        'green'  => 142.0,   // #22c55e
        'teal'   => 173.0,   // #14b8a6
        'blue'   => 217.0,   // #3b82f6
        'violet' => 258.0,   // #8b5cf6
        'pink'   => 330.0,   // #ec4899
    ];

    /**
     * Below this there is not enough colour left to have a hue worth reading,
     * and the value is a grey however it was authored. Gmail's palette runs
     * from #000000 to #efefef through several near-neutrals, so this decides
     * roughly a third of it.
     */
    private const float GREY_SATURATION = 0.18;

    /**
     * Token to the {background, text} pair sent to Gmail. Every value here is
     * one of the 89 the API accepts — it rejects anything else — and the text
     * colour is whichever of black or white stays legible on the background.
     *
     * @var array<string, array{string, string}>
     */
    private const array OUTBOUND = [
        'gray'   => ['#666666', '#ffffff'],
        'red'    => ['#fb4c2f', '#ffffff'],
        'orange' => ['#ffad47', '#000000'],
        'amber'  => ['#fad165', '#000000'],
        'green'  => ['#16a766', '#ffffff'],
        'teal'   => ['#2da2bb', '#ffffff'],
        'blue'   => ['#4a86e8', '#ffffff'],
        'violet' => ['#a479e2', '#ffffff'],
        'pink'   => ['#f691b3', '#000000'],
    ];

    /**
     * The background of a Gmail label to one of ours.
     *
     * Null for an absent or unreadable value rather than a guess: a label with
     * no colour is one the user can still choose for, and one silently made
     * the wrong colour is not.
     */
    public function toLabelColor(?string $backgroundColor): ?LabelColor
    {
        $rgb = $this->toRgb($backgroundColor);

        if (null === $rgb) {
            return null;
        }

        [$hue, $saturation] = $this->hueAndSaturation($rgb);

        if ($saturation < self::GREY_SATURATION) {
            return LabelColor::Gray;
        }

        $nearest  = LabelColor::Gray;
        $smallest = 360.0;

        foreach (self::HUES as $token => $reference) {
            // Circular: red sits at 0, so a hue of 355 is five degrees away
            // from it rather than three hundred and fifty-five.
            $distance = abs($hue - $reference);
            $distance = min($distance, 360.0 - $distance);

            if ($distance < $smallest) {
                $smallest = $distance;
                $nearest  = LabelColor::from($token);
            }
        }

        return $nearest;
    }

    /**
     * @return array{backgroundColor: string, textColor: string}|null
     *         null when plMail has no colour to send — Gmail takes the pair or
     *         neither, so there is no half of this worth sending
     */
    public function toGmailColor(?LabelColor $color): ?array
    {
        if (null === $color || false === array_key_exists($color->value, self::OUTBOUND)) {
            return null;
        }

        [$background, $text] = self::OUTBOUND[$color->value];

        return ['backgroundColor' => $background, 'textColor' => $text];
    }

    /**
     * @return array{int, int, int}|null
     */
    private function toRgb(?string $hex): ?array
    {
        $hex = ltrim(trim((string) $hex), '#');

        if (3 === strlen($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (1 !== preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @param array{int, int, int} $rgb
     *
     * @return array{float, float} hue in degrees, saturation 0–1
     */
    private function hueAndSaturation(array $rgb): array
    {
        [$r, $g, $b] = array_map(static fn (int $c): float => $c / 255.0, $rgb);

        $max   = max($r, $g, $b);
        $min   = min($r, $g, $b);
        $delta = $max - $min;

        if (0.0 === $delta) {
            return [0.0, 0.0];
        }

        $hue = match (true) {
            $max === $r => 60.0 * fmod(($g - $b) / $delta, 6.0),
            $max === $g => 60.0 * ((($b - $r) / $delta) + 2.0),
            default     => 60.0 * ((($r - $g) / $delta) + 4.0),
        };

        if ($hue < 0.0) {
            $hue += 360.0;
        }

        $lightness  = ($max + $min) / 2.0;
        $saturation = 1.0 === abs(2.0 * $lightness - 1.0)
            ? 0.0
            : $delta / (1.0 - abs(2.0 * $lightness - 1.0));

        return [$hue, min(1.0, $saturation)];
    }
}
