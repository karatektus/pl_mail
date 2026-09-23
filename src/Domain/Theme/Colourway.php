<?php

declare(strict_types=1);

namespace App\Domain\Theme;

use App\Domain\Enum\Theme\LogoStyle;
use App\Domain\Helper\ColourHelper;

/**
 * A logo colourway read as roles, so it can dress an icon that is not the pl
 * mark.
 *
 * A LogoStyle is paint for the mark's seven strokes and nothing else. The other
 * nine motifs have no seven strokes — a horn has a body and a throat, a letter
 * has paper, a line and a heart — so before a colourway can paint one it has to
 * be READ: which colour draws, whether there is a second colour, what a pale
 * ground should be tinted from. The reading lives here, once; each motif's
 * recipe (LogoMotif) then only says where those roles go. Nine recipes reading
 * the colourways nine ways is how "Postal" would come to mean navy on the horn
 * and red on the letter.
 *
 * Five families, told apart by the strokes themselves rather than by a list, so
 * a colourway added to LogoStyle is read correctly the day it is added:
 *
 *  - mono       one colour everywhere. `one` is it; there is no `two`.
 *  - duo        the p in one colour, the l in another: `one` and `two`.
 *  - flick      ink with a coloured tail. `one` is the ink, `two` the tail.
 *  - tricolore  navy, red and gold, `one` and `two` the first two, the gold what
 *               a pale ground is tinted from. The one family named rather than
 *               detected: by its strokes it would read as a sweep.
 *  - sweep      a ramp across the strokes. `one` is the colourway's tile colour,
 *               for wherever a single solid is needed, and paint() hands out the
 *               ramp itself.
 *
 * Only the LIGHT strokes are read. The icons are drawn on their own grounds, not
 * on the chrome, so the dark-chrome lists — which exist to keep an ink mark from
 * vanishing into a dark topbar — have nothing to say here.
 */
final readonly class Colourway
{
    public const string MONO = 'mono';
    public const string DUO = 'duo';
    public const string FLICK = 'flick';
    public const string TRICOLORE = 'tricolore';
    public const string SWEEP = 'sweep';

    /** One of the five constants above. */
    public string $family;

    /** The colour that draws. */
    public string $one;

    /** The second colour, where the colourway has one. */
    public ?string $two;

    /** What a pale ground is tinted from. */
    public string $ground;

    /** LogoStyle::tile() — the colourway's one representative colour. */
    public string $rep;

    /**
     * The seven light strokes, in draw order — the ramp, for a sweep.
     *
     * @var list<string>
     */
    public array $strokes;

    public function __construct(public LogoStyle $style)
    {
        $this->strokes = $style->strokes();
        $this->rep = $style->tile();
        $this->family = self::familyOf($style, $this->strokes);

        [$this->one, $this->two] = match ($this->family) {
            self::MONO => [$this->strokes[0], null],
            self::DUO => [$this->strokes[0], $this->strokes[6]],
            self::FLICK => [LogoStyle::INK, $this->strokes[6]],
            self::TRICOLORE => [$this->strokes[0], $this->strokes[1]],
            default => [$this->rep, null],
        };

        $this->ground = match ($this->family) {
            self::TRICOLORE => $this->strokes[4],
            self::SWEEP => $this->rep,
            default => $this->two ?? $this->one,
        };
    }

    /**
     * `one` as a paint: the colour itself, or for a sweep the whole ramp,
     * which the icon template draws corner to corner across the glyph.
     *
     * @return string|array{ramp: list<string>}
     */
    public function paint(): string|array
    {
        return self::SWEEP === $this->family ? ['ramp' => $this->strokes] : $this->one;
    }

    /**
     * `one` across a whole tile — or, given a luminance, a pastel of the
     * ground instead: the mood behind a drawing that keeps its own colours.
     * A sweep answers a ramp either way, pastel stop by pastel stop.
     *
     * @return string|array{ramp: list<string>}
     */
    public function tile(?float $pastel = null): string|array
    {
        if (self::SWEEP !== $this->family) {
            return null === $pastel ? $this->one : ColourHelper::soft($this->ground, $pastel);
        }

        return ['ramp' => null === $pastel
            ? $this->strokes
            : array_map(static fn (string $stop): string => ColourHelper::soft($stop, $pastel), $this->strokes),
        ];
    }

    /** @param list<string> $strokes */
    private static function familyOf(LogoStyle $style, array $strokes): string
    {
        if (LogoStyle::Tricolore === $style) {
            return self::TRICOLORE;
        }

        if (1 === count(array_unique($strokes))) {
            return self::MONO;
        }

        if ([LogoStyle::INK] === array_values(array_unique(array_slice($strokes, 0, 6)))) {
            return self::FLICK;
        }

        if (1 === count(array_unique(array_slice($strokes, 0, 4))) && 1 === count(array_unique(array_slice($strokes, 4)))) {
            return self::DUO;
        }

        return self::SWEEP;
    }
}
