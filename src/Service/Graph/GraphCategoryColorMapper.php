<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Domain\Enum\Mail\LabelColor;

/**
 * Outlook category colours to plMail's, and back.
 *
 * Graph's `color` is a closed enum — `none` plus preset0–preset24, twenty-five
 * and no more — and it carries no hex. Microsoft documents each preset as a
 * colour NAME and says outright that "the actual color is dependent on the
 * Outlook client that the categories are being displayed in", so there is
 * nothing to sample and matching by name is not an approximation of a better
 * method, it is the only method.
 *
 * Twenty-five names onto nine tokens is lossy, and the previous decision here
 * was to drop the colour entirely rather than accept that. The reason given
 * was drift on every sync, which is a real hazard for a BIDIRECTIONAL map:
 * preset15 (DarkRed) arrives as red, red leaves as preset0 (Red), and the
 * user's dark red is gone. That hazard is avoided by never writing back a
 * colour we did not originate:
 *
 *   - inbound runs only when the label has no colour yet, so a colour the user
 *     picked here is never overwritten by a sync, and a shade Outlook owns is
 *     read once and then left alone;
 *   - outbound runs only when plMail CREATES a category, where there is no
 *     existing Outlook colour to lose.
 *
 * Nothing round-trips repeatedly, so nothing drifts. A colourless label still
 * gets its colour, which is the thing that was actually costing users.
 */
final readonly class GraphCategoryColorMapper
{
    /**
     * Preset to token. The light and dark variants of a hue collapse onto the
     * same token — there is no darker red here to map DarkRed onto, and a
     * near-enough red is worth more than no colour.
     *
     * Olive is a dark yellow-green and lands on green; Steel is a blue-grey
     * and lands on gray rather than blue, since that is what it reads as at
     * chip size. Black has no token and is gray for the same reason.
     *
     * @var array<string, LabelColor>
     */
    private const array INBOUND = [
        'preset0'  => LabelColor::Red,
        'preset1'  => LabelColor::Orange,
        'preset2'  => LabelColor::Orange,   // Brown
        'preset3'  => LabelColor::Amber,    // Yellow
        'preset4'  => LabelColor::Green,
        'preset5'  => LabelColor::Teal,
        'preset6'  => LabelColor::Green,    // Olive
        'preset7'  => LabelColor::Blue,
        'preset8'  => LabelColor::Violet,   // Purple
        'preset9'  => LabelColor::Pink,     // Cranberry
        'preset10' => LabelColor::Gray,     // Steel
        'preset11' => LabelColor::Gray,     // DarkSteel
        'preset12' => LabelColor::Gray,
        'preset13' => LabelColor::Gray,     // DarkGray
        'preset14' => LabelColor::Gray,     // Black
        'preset15' => LabelColor::Red,      // DarkRed
        'preset16' => LabelColor::Orange,   // DarkOrange
        'preset17' => LabelColor::Orange,   // DarkBrown
        'preset18' => LabelColor::Amber,    // DarkYellow
        'preset19' => LabelColor::Green,    // DarkGreen
        'preset20' => LabelColor::Teal,     // DarkTeal
        'preset21' => LabelColor::Green,    // DarkOlive
        'preset22' => LabelColor::Blue,     // DarkBlue
        'preset23' => LabelColor::Violet,   // DarkPurple
        'preset24' => LabelColor::Pink,     // DarkCranberry
    ];

    /**
     * Token to preset, using the lightest preset of each hue — Outlook renders
     * these on a white category strip, where the dark variants read as muddy.
     *
     * @var array<string, string>
     */
    private const array OUTBOUND = [
        'gray'   => 'preset12',
        'red'    => 'preset0',
        'orange' => 'preset1',
        'amber'  => 'preset3',
        'green'  => 'preset4',
        'teal'   => 'preset5',
        'blue'   => 'preset7',
        'violet' => 'preset8',
        'pink'   => 'preset9',
    ];

    /** Graph's own "no colour", and what we send when plMail has none either. */
    public const string NO_COLOR = 'none';

    /**
     * Null for `none`, for an absent value, and for any preset Microsoft adds
     * later — an unknown constant is not guessed at, it is left uncoloured so
     * the user can pick.
     */
    public function toLabelColor(?string $preset): ?LabelColor
    {
        return self::INBOUND[mb_strtolower(trim((string) $preset))] ?? null;
    }

    public function toPreset(?LabelColor $color): string
    {
        return null === $color
            ? self::NO_COLOR
            : (self::OUTBOUND[$color->value] ?? self::NO_COLOR);
    }
}
