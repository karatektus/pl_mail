<?php

declare(strict_types=1);

namespace App\Domain\Enum\Theme;

use App\Domain\Helper\ColourHelper;
use App\Domain\Theme\Colourway;

/**
 * The product's icon — the pl mark, or one of nine motifs beside it.
 *
 * Choosing a logo is two steps: the icon, then its paint. The paint is either
 * the icon's own ORIGINAL design, which is what a newly picked motif wears, or
 * one of LogoStyle's thirty-two colourways. Nothing is stored per combination:
 * each motif is one glyph template (templates/branding/motif/<value>.svg.twig)
 * and one recipe here, and the two meet when an icon is drawn.
 *
 * A recipe repaints only the motif's LIVERY. The parts that make it the thing
 * it is never change: the love letter's heart is red, par avion's stripes are
 * red and blue, the snail is green in its garden, the mailbox flag is up and
 * red. A colourway applied to one of those says where ITS colours go and leaves
 * the identity alone — which is why "ember" on the mailbox is an ember box with
 * a red flag, and not an ember flag nobody can find on an ember box.
 *
 * The pl mark is the exception that is not one. Its seven strokes ARE its
 * livery, so a colourway is simply its seven colours on the mark's off-white
 * ground, and its "original" is the product default rather than a design of its
 * own.
 *
 * THE TABLE IS SHARED. paints() for every motif × paint is exactly what the
 * Android build draws its launcher icons from — a committed copy of the same
 * table (tests/Domain/Enum/Theme/fixtures/logo-paints.json), printed by
 * `app:branding:export-paints`. LogoMotifTest holds the two equal, so a recipe
 * changed here without the fixture being regenerated fails a test rather than
 * leaving the phone and the web disagreeing about an icon.
 *
 * The values are wire values — JMAP's `logoMotif`, the icon route, the session
 * vocabulary — and are fixed; the order is the order clients list them in.
 */
enum LogoMotif: string
{
    case Pl = 'pl';
    case BlueHorn = 'blue-horn';
    case AtHorn = 'at-horn';
    case LoveLetter = 'love-letter';
    case Airmail = 'airmail';
    case HappyMail = 'happy-mail';
    case SnailMail = 'snail-mail';
    case Mailbox = 'mailbox';
    case PlStamp = 'pl-stamp';
    case WaxSeal = 'wax-seal';

    /** The mark every account has always had, and the one it starts on. */
    public const self DEFAULT = self::Pl;

    /**
     * The paint that is a motif's own design rather than a colourway — the
     * first word of the paint vocabulary, before LogoStyle's values.
     */
    public const string ORIGINAL = 'original';

    // ── The colours that are not the colourway's ──────────────────────────
    // Identity parts and fixed grounds, named once so a recipe reads as where
    // things go rather than as a wall of hex.
    private const string WHITE = '#ffffff';
    private const string OFF_WHITE = '#faf9f7';
    private const string CREAM = '#f7f1e6';
    private const string PAPER_WARM = '#fffaf0';
    private const string FACE = '#45372b';
    private const string NAVY = '#1e3a6e';
    private const string ROSE = '#f43f5e';
    private const string PINK = '#fb7185';
    private const string FLAG = '#e0452f';
    private const string AIR_RED = '#d93a2e';
    private const string AIR_BLUE = '#2a5bd7';
    private const string SNAIL = '#4b8f3f';
    private const string MINT = '#e4f0da';
    private const string WALL = '#f4ecdd';
    private const string SEAL_PAPER = '#f3eadb';

    /** How pale a happy-mail ground is: a mood behind the face, not a colour. */
    private const float PASTEL = 0.72;

    /**
     * The parts a paint names, in the order the table lists them — the glyph
     * template's `p.<part>` variables. Every paint also names `background`,
     * the tile the glyph sits on, which is not a part of the glyph.
     *
     * @return list<string>
     */
    public function parts(): array
    {
        return match ($this) {
            self::Pl => ['s0', 's1', 's2', 's3', 's4', 's5', 's6'],
            self::BlueHorn, self::AtHorn => ['horn', 'throat'],
            self::LoveLetter => ['paper', 'line', 'heart'],
            self::Airmail => ['paper', 'red', 'blue', 'edge', 'stamp', 'text'],
            self::HappyMail => ['paper', 'line', 'cheek'],
            self::SnailMail => ['body', 'paper', 'line', 'seal'],
            self::Mailbox => ['box', 'paper', 'flag', 'post'],
            self::PlStamp => ['stamp', 'mark0', 'mark1', 'mark2', 'mark3', 'mark4', 'mark5', 'mark6', 'frame'],
            self::WaxSeal => ['wax', 'ring', 'mark'],
        };
    }

    /**
     * This motif in one paint: `background` first, then every part in parts()
     * order. Null is the original design.
     *
     * A value is a `#rrggbb`, a null for a part that is not drawn at all (the
     * snail's seal, when there is no second colour to seal with), or a ramp of
     * seven stops for a sweep — drawn corner to corner across the glyph, from
     * (8,6) to (40,42) on its 48 grid, or across the tile from (18,18) to
     * (90,90) on the 108 canvas when it is the background.
     *
     * @return array<string, string|array{ramp: list<string>}|null>
     */
    public function paints(?LogoStyle $paint): array
    {
        $paints = null === $paint ? $this->original() : $this->livery(new Colourway($paint));

        $ordered = ['background' => $paints['background']];

        foreach ($this->parts() as $part) {
            $ordered[$part] = $paints[$part] ?? null;
        }

        return $ordered;
    }

    /**
     * The paint vocabulary, in contract order: the original, then every
     * colourway. What the icon route accepts and the export walks.
     *
     * @return list<string>
     */
    public static function paintWires(): array
    {
        return [self::ORIGINAL, ...array_column(LogoStyle::cases(), 'value')];
    }

    /** A paint as the wire spells it — null, the original, is `original`. */
    public static function paintWire(?LogoStyle $paint): string
    {
        return null === $paint ? self::ORIGINAL : $paint->value;
    }

    /**
     * Each motif's own design, as the board drew it. Hard values rather than a
     * recipe run on some colourway: an original is a design, and most of them
     * are not any colourway at all.
     *
     * @return array<string, string|null>
     */
    private function original(): array
    {
        return match ($this) {
            // The product default: the mark's own original IS a colourway.
            self::Pl => self::plMark(LogoStyle::DEFAULT),
            self::BlueHorn => ['background' => self::WALL, 'horn' => self::AIR_BLUE, 'throat' => '#193e9c'],
            self::AtHorn => ['background' => self::AIR_BLUE, 'horn' => self::CREAM, 'throat' => '#cdd9f5'],
            self::LoveLetter => ['background' => '#ffe4e6', 'paper' => self::WHITE, 'line' => '#9f1239', 'heart' => self::ROSE],
            self::Airmail => [
                'background' => '#e3ebf8',
                'paper'      => self::WHITE,
                'red'        => self::AIR_RED,
                'blue'       => self::AIR_BLUE,
                'edge'       => '#c9d3e6',
                'stamp'      => self::AIR_BLUE,
                'text'       => self::NAVY,
            ],
            self::HappyMail => ['background' => '#fdeeb5', 'paper' => self::PAPER_WARM, 'line' => self::FACE, 'cheek' => self::PINK],
            // No seal: the original has one colour for its envelope, and a seal
            // is what a SECOND colour is for (see livery()).
            self::SnailMail => ['background' => self::MINT, 'body' => self::SNAIL, 'paper' => self::PAPER_WARM, 'line' => '#8a5a2b', 'seal' => null],
            self::Mailbox => ['background' => '#e6eefb', 'box' => self::AIR_BLUE, 'paper' => self::WHITE, 'flag' => self::FLAG, 'post' => LogoStyle::INK],
            self::PlStamp => ['background' => '#f5eaf6', 'stamp' => '#a21caf', ...self::marks(array_fill(0, 7, self::WHITE)), 'frame' => '#d18ed7'],
            self::WaxSeal => ['background' => self::SEAL_PAPER, 'wax' => '#b3261e', 'ring' => '#8c1d18', 'mark' => '#fde2d8'],
        };
    }

    /**
     * The recipes: where a colourway's roles go on each motif. Each arm's
     * comment is the sentence the design board wrote for it.
     *
     * @return array<string, string|array{ramp: list<string>}|null>
     */
    private function livery(Colourway $way): array
    {
        return match ($this) {
            self::Pl => self::plMark($way->style),

            // The horn is repainted; a second colour lines the bell. The cream
            // wall stays.
            self::BlueHorn => [
                'background' => self::WALL,
                'horn'       => $way->paint(),
                'throat'     => $way->two ?? ColourHelper::shade($way->one, 0.4),
            ],

            // The field takes the colour; the @ and its horn stay cream.
            self::AtHorn => [
                'background' => $way->tile(),
                'horn'       => self::CREAM,
                'throat'     => $way->two ?? ColourHelper::tint($way->one, 0.7),
            ],

            // The envelope takes the colour. The heart is always red.
            self::LoveLetter => [
                'background' => ColourHelper::tint($way->ground),
                'paper'      => self::WHITE,
                'line'       => $way->paint(),
                'heart'      => self::ROSE,
            ],

            // Red and blue stripes are par avion and stay. The stamp and the
            // ground change — the stamp in the flick's colour rather than its
            // ink, because an ink stamp on a pale envelope is the one thing
            // on it that says nothing.
            self::Airmail => [
                'background' => ColourHelper::tint($way->ground),
                'paper'      => self::WHITE,
                'red'        => self::AIR_RED,
                'blue'       => self::AIR_BLUE,
                'edge'       => ColourHelper::shade(ColourHelper::tint($way->ground), 0.12),
                'stamp'      => Colourway::FLICK === $way->family ? $way->two : $way->one,
                'text'       => self::NAVY,
            ],

            // The face and the blush stay. The mood behind it changes.
            self::HappyMail => [
                'background' => $way->tile(self::PASTEL),
                'paper'      => self::PAPER_WARM,
                'line'       => self::FACE,
                'cheek'      => self::PINK,
            ],

            // The snail stays green in its garden. Its envelope takes the
            // colour; a second colour, where there is one, seals it.
            self::SnailMail => [
                'background' => self::MINT,
                'body'       => self::SNAIL,
                'paper'      => self::PAPER_WARM,
                'line'       => $way->paint(),
                'seal'       => $way->two,
            ],

            // The box is painted in the colour. The flag is always red.
            self::Mailbox => [
                'background' => ColourHelper::tint($way->ground),
                'box'        => $way->paint(),
                'paper'      => self::WHITE,
                'flag'       => self::FLAG,
                'post'       => LogoStyle::INK,
            ],

            // The stamp takes the colour and the pl is cut out of it in white —
            // except where the colourway itself uses its second colour (the
            // duo's l, the flick's tail, tricolore's red and gold), which stays
            // in the strokes it paints on the mark. A sweep's stamp is the ramp,
            // so its pl is all white.
            self::PlStamp => [
                'background' => ColourHelper::tint($way->rep),
                'stamp'      => $way->paint(),
                ...self::marks(Colourway::SWEEP === $way->family
                    ? array_fill(0, 7, self::WHITE)
                    : array_map(static fn (string $stroke): string => $stroke === $way->one ? self::WHITE : $stroke, $way->strokes)),
                'frame'      => ColourHelper::mix($way->one, self::WHITE, 0.5),
            ],

            // One colour of wax, never a gradient: `one`, which is a sweep's
            // tile colour and a flick's ink. The mark is pressed into it in the
            // second colour, or in the wax's own pale where there is none.
            self::WaxSeal => [
                'background' => self::SEAL_PAPER,
                'wax'        => $way->one,
                'ring'       => ColourHelper::shade($way->one, 0.3),
                'mark'       => null !== $way->two ? ColourHelper::tint($way->two, 0.45) : ColourHelper::tint($way->one, 0.85),
            ],
        };
    }

    /** @return array<string, string> */
    private static function plMark(LogoStyle $style): array
    {
        $paints = ['background' => self::OFF_WHITE];

        foreach ($style->strokes() as $index => $stroke) {
            $paints['s' . $index] = $stroke;
        }

        return $paints;
    }

    /**
     * The stamp's seven pl strokes, one part each.
     *
     * @param list<string> $strokes
     *
     * @return array<string, string>
     */
    private static function marks(array $strokes): array
    {
        $marks = [];

        foreach ($strokes as $index => $stroke) {
            $marks['mark' . $index] = $stroke;
        }

        return $marks;
    }
}
