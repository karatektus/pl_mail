<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * The colour one calendar event carries of its own, as the standards spell it.
 *
 * Both of them spell it as a CSS colour NAME. iCalendar's COLOR property (RFC
 * 7986 §5.9) is "a CSS3 color name" and nothing else; JSCalendar's `color`
 * (RFC 8984 §4.2.9) takes the same names and also allows "#rrggbb". So a phone
 * that colours an event writes `COLOR:turquoise` — DAVx5 rounds Android's
 * arbitrary colour to the nearest name to be able to say it at all — and that
 * is the value plMail reads, stores under `jscalendar.color` and writes back.
 *
 * **What is stored is what arrived, never what this class makes of it.** A
 * client that ignores the RFC and writes `COLOR:#ff8800`, or a name from CSS4
 * nobody here knows, still gets its value back untouched on the next PUT. This
 * class only answers what to DRAW for a value (hexOf()), and an answer of null
 * means "draw the calendar's colour", not "throw it away" — see
 * CalendarEventWriter, which carries the stored value across every edit that
 * does not name a new one.
 *
 * NAMES is CSS Color Module Level 3's full table, the 147 names RFC 7986 points
 * at. PICKER is the editor's own subset: forty that read as a colour on both
 * the Paper and the Dark theme (no white, no near-whites, no black) and that
 * get a translated label each. The first twenty, in hue order, are the swatch
 * grid; the other twenty are reached by searching. A name outside the forty is
 * still drawn, and the editor labels it with the name itself — see label().
 */
final readonly class EventColor
{
    /**
     * The picker's colours, swatch grid first: five per row, one hue family a
     * row, so the grid reads as reds → greens → blues → purples rather than as
     * a scatter. The order is the order the template draws.
     *
     * @var list<string>
     */
    public const array PICKER = [
        // The grid.
        'crimson', 'tomato', 'darkorange', 'goldenrod', 'gold',
        'yellowgreen', 'olivedrab', 'seagreen', 'mediumseagreen', 'lightseagreen',
        'teal', 'darkturquoise', 'steelblue', 'royalblue', 'slateblue',
        'mediumpurple', 'orchid', 'mediumvioletred', 'palevioletred', 'sienna',
        // Search only.
        'firebrick', 'coral', 'chocolate', 'peru', 'darkgoldenrod',
        'darkkhaki', 'limegreen', 'forestgreen', 'turquoise', 'cadetblue',
        'dodgerblue', 'cornflowerblue', 'midnightblue', 'indigo', 'darkmagenta',
        'hotpink', 'rosybrown', 'saddlebrown', 'slategray', 'dimgray',
    ];

    /** How many of PICKER the swatch grid shows. */
    public const int GRID_SIZE = 20;

    /**
     * CSS Color Module Level 3, §4.3 "Extended color keywords": every name
     * COLOR may carry, and the colour each one means.
     *
     * @var array<string,string>
     */
    public const array NAMES = [
        'aliceblue'            => '#f0f8ff', 'antiquewhite'         => '#faebd7', 'aqua'                 => '#00ffff',
        'aquamarine'           => '#7fffd4', 'azure'                => '#f0ffff', 'beige'                => '#f5f5dc',
        'bisque'               => '#ffe4c4', 'black'                => '#000000', 'blanchedalmond'       => '#ffebcd',
        'blue'                 => '#0000ff', 'blueviolet'           => '#8a2be2', 'brown'                => '#a52a2a',
        'burlywood'            => '#deb887', 'cadetblue'            => '#5f9ea0', 'chartreuse'           => '#7fff00',
        'chocolate'            => '#d2691e', 'coral'                => '#ff7f50', 'cornflowerblue'       => '#6495ed',
        'cornsilk'             => '#fff8dc', 'crimson'              => '#dc143c', 'cyan'                 => '#00ffff',
        'darkblue'             => '#00008b', 'darkcyan'             => '#008b8b', 'darkgoldenrod'        => '#b8860b',
        'darkgray'             => '#a9a9a9', 'darkgreen'            => '#006400', 'darkgrey'             => '#a9a9a9',
        'darkkhaki'            => '#bdb76b', 'darkmagenta'          => '#8b008b', 'darkolivegreen'       => '#556b2f',
        'darkorange'           => '#ff8c00', 'darkorchid'           => '#9932cc', 'darkred'              => '#8b0000',
        'darksalmon'           => '#e9967a', 'darkseagreen'         => '#8fbc8f', 'darkslateblue'        => '#483d8b',
        'darkslategray'        => '#2f4f4f', 'darkslategrey'        => '#2f4f4f', 'darkturquoise'        => '#00ced1',
        'darkviolet'           => '#9400d3', 'deeppink'             => '#ff1493', 'deepskyblue'          => '#00bfff',
        'dimgray'              => '#696969', 'dimgrey'              => '#696969', 'dodgerblue'           => '#1e90ff',
        'firebrick'            => '#b22222', 'floralwhite'          => '#fffaf0', 'forestgreen'          => '#228b22',
        'fuchsia'              => '#ff00ff', 'gainsboro'            => '#dcdcdc', 'ghostwhite'           => '#f8f8ff',
        'gold'                 => '#ffd700', 'goldenrod'            => '#daa520', 'gray'                 => '#808080',
        'green'                => '#008000', 'greenyellow'          => '#adff2f', 'grey'                 => '#808080',
        'honeydew'             => '#f0fff0', 'hotpink'              => '#ff69b4', 'indianred'            => '#cd5c5c',
        'indigo'               => '#4b0082', 'ivory'                => '#fffff0', 'khaki'                => '#f0e68c',
        'lavender'             => '#e6e6fa', 'lavenderblush'        => '#fff0f5', 'lawngreen'            => '#7cfc00',
        'lemonchiffon'         => '#fffacd', 'lightblue'            => '#add8e6', 'lightcoral'           => '#f08080',
        'lightcyan'            => '#e0ffff', 'lightgoldenrodyellow' => '#fafad2', 'lightgray'            => '#d3d3d3',
        'lightgreen'           => '#90ee90', 'lightgrey'            => '#d3d3d3', 'lightpink'            => '#ffb6c1',
        'lightsalmon'          => '#ffa07a', 'lightseagreen'        => '#20b2aa', 'lightskyblue'         => '#87cefa',
        'lightslategray'       => '#778899', 'lightslategrey'       => '#778899', 'lightsteelblue'       => '#b0c4de',
        'lightyellow'          => '#ffffe0', 'lime'                 => '#00ff00', 'limegreen'            => '#32cd32',
        'linen'                => '#faf0e6', 'magenta'              => '#ff00ff', 'maroon'               => '#800000',
        'mediumaquamarine'     => '#66cdaa', 'mediumblue'           => '#0000cd', 'mediumorchid'         => '#ba55d3',
        'mediumpurple'         => '#9370db', 'mediumseagreen'       => '#3cb371', 'mediumslateblue'      => '#7b68ee',
        'mediumspringgreen'    => '#00fa9a', 'mediumturquoise'      => '#48d1cc', 'mediumvioletred'      => '#c71585',
        'midnightblue'         => '#191970', 'mintcream'            => '#f5fffa', 'mistyrose'            => '#ffe4e1',
        'moccasin'             => '#ffe4b5', 'navajowhite'          => '#ffdead', 'navy'                 => '#000080',
        'oldlace'              => '#fdf5e6', 'olive'                => '#808000', 'olivedrab'            => '#6b8e23',
        'orange'               => '#ffa500', 'orangered'            => '#ff4500', 'orchid'               => '#da70d6',
        'palegoldenrod'        => '#eee8aa', 'palegreen'            => '#98fb98', 'paleturquoise'        => '#afeeee',
        'palevioletred'        => '#db7093', 'papayawhip'           => '#ffefd5', 'peachpuff'            => '#ffdab9',
        'peru'                 => '#cd853f', 'pink'                 => '#ffc0cb', 'plum'                 => '#dda0dd',
        'powderblue'           => '#b0e0e6', 'purple'               => '#800080', 'red'                  => '#ff0000',
        'rosybrown'            => '#bc8f8f', 'royalblue'            => '#4169e1', 'saddlebrown'          => '#8b4513',
        'salmon'               => '#fa8072', 'sandybrown'           => '#f4a460', 'seagreen'             => '#2e8b57',
        'seashell'             => '#fff5ee', 'sienna'               => '#a0522d', 'silver'               => '#c0c0c0',
        'skyblue'              => '#87ceeb', 'slateblue'            => '#6a5acd', 'slategray'            => '#708090',
        'slategrey'            => '#708090', 'snow'                 => '#fffafa', 'springgreen'          => '#00ff7f',
        'steelblue'            => '#4682b4', 'tan'                  => '#d2b48c', 'teal'                 => '#008080',
        'thistle'              => '#d8bfd8', 'tomato'               => '#ff6347', 'turquoise'            => '#40e0d0',
        'violet'               => '#ee82ee', 'wheat'                => '#f5deb3', 'white'                => '#ffffff',
        'whitesmoke'           => '#f5f5f5', 'yellow'               => '#ffff00', 'yellowgreen'          => '#9acd32',
    ];

    /**
     * What to draw for a stored value: "#rrggbb", lower case, or null when the
     * value is not a colour this class can name.
     *
     * Six digits always, because every template that draws an event appends
     * two more for the alpha (`{{ colour }}1a`) — a "#f80" that came in as
     * three would turn into "#f801a", which no browser reads.
     *
     * Case and surrounding space are forgiven: RFC 7986 does not say COLOR is
     * case-sensitive and CSS says names are not, and "Turquoise" from one
     * client is the same colour as "turquoise" from the next.
     */
    public static function hexOf(mixed $value): ?string
    {
        if (false === is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        if (true === isset(self::NAMES[$value])) {
            return self::NAMES[$value];
        }

        if (1 === preg_match('/^#([0-9a-f]{6})$/', $value)) {
            return $value;
        }

        if (1 === preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $digits)) {
            return '#' . $digits[1] . $digits[1] . $digits[2] . $digits[2] . $digits[3] . $digits[3];
        }

        return null;
    }

    /**
     * The name a stored value is, in the case NAMES keys it by, or null for a
     * hex value or anything unknown.
     */
    public static function nameOf(mixed $value): ?string
    {
        if (false === is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return true === isset(self::NAMES[$value]) ? $value : null;
    }

    /**
     * The translation key of a name the editor has a label for, or null.
     *
     * Null for the 107 names outside PICKER. Those still turn up — a phone that
     * rounded to `lightsteelblue` — and the editor shows them by the name
     * itself rather than carrying 147 colour names through five catalogues.
     */
    public static function label(string $name): ?string
    {
        return true === in_array($name, self::PICKER, true) ? 'calendar.event.color.names.' . $name : null;
    }

    /**
     * The name in PICKER closest to a hex colour, for a hex somebody typed into
     * the editor's search: COLOR cannot store it, so the nearest name is
     * offered instead.
     *
     * Closest in plain RGB distance. A perceptual space (CIELAB) picks better
     * between two near neighbours, but the candidates here are forty colours
     * chosen to be far apart, and the editor shows the swatch beside the name
     * — nobody is asked to take the match on trust.
     */
    public static function nearestPickerName(string $hex): ?string
    {
        $target = self::hexOf($hex);

        if (null === $target) {
            return null;
        }

        $wanted   = self::channels($target);
        $best     = null;
        $distance = PHP_INT_MAX;

        foreach (self::PICKER as $name) {
            $have = self::channels(self::NAMES[$name]);
            $d    = ($have[0] - $wanted[0]) ** 2 + ($have[1] - $wanted[1]) ** 2 + ($have[2] - $wanted[2]) ** 2;

            if ($d < $distance) {
                $distance = $d;
                $best     = $name;
            }
        }

        return $best;
    }

    /**
     * Ink or white, whichever is the more legible on top of a colour: what the
     * tick in a swatch and the chevron in the title's dot are drawn in, so gold
     * gets a dark tick and midnightblue a white one.
     *
     * WCAG contrast against both, the larger wins. The ink is the Paper theme's
     * (#232220); the swatches are the same colour in every theme, so this does
     * not follow the theme either.
     */
    public static function onColor(string $hex): string
    {
        $luminance = ColourHelper::luminance($hex);
        $ink       = ColourHelper::luminance('#232220');

        $againstWhite = 1.05 / ($luminance + 0.05);
        $againstInk   = ($luminance + 0.05) / ($ink + 0.05);

        return $againstWhite >= $againstInk ? '#ffffff' : '#232220';
    }

    /**
     * @return array{int,int,int}
     */
    private static function channels(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }
}
