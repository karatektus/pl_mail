<?php

declare(strict_types=1);

namespace App\Domain\Enum\Theme;

/**
 * The colourway of the "pl" mark — a user choice, like the theme beside it.
 *
 * The mark itself is seven strokes in a fixed draw order (see
 * _partials/_logo_mark.html.twig, the one place the geometry lives):
 * p-stem, p-top-curve, p-right-stem, p-bowl-bottom, l-upper, l-lower, l-tail.
 * A style is nothing but paint for those seven, in that order — one list for
 * light chrome and one for dark, because several styles draw in near-ink and
 * near-ink on a dark topbar is a mark that is not there.
 *
 * The catalogue is the design board's, minus two on purpose: the original
 * colourway was Google's literal brand hexes and is the reason this enum
 * exists, and the board's option F kept four-distinct-colours-per-stroke,
 * which read as that brand however the hues were shifted. Neither is a thing
 * a user should be able to put back.
 *
 * Everything reads the mark through this enum — the topbar, the settings
 * tiles, the favicon route — so a new style is one case here and nothing
 * anywhere else.
 */
enum LogoStyle: string
{
    // ── Single colour ─────────────────────────────────────────────────────
    case ProductBlue = 'product-blue';
    case Ink = 'ink';
    case Slate = 'slate';
    case Forest = 'forest';
    case Burgundy = 'burgundy';
    case DeepViolet = 'deep-violet';

    // ── Duotones: the p one colour, the l another ─────────────────────────
    case Postal = 'postal';
    case BronzeInk = 'bronze-ink';
    case PetrolCopper = 'petrol-copper';
    case MidnightGold = 'midnight-gold';
    case NavySky = 'navy-sky';
    case ForestLime = 'forest-lime';
    case PlumRose = 'plum-rose';
    case CharcoalAmber = 'charcoal-amber';
    case EspressoCream = 'espresso-cream';
    case CobaltCoral = 'cobalt-coral';
    case TealGold = 'teal-gold';
    case GraphiteRed = 'graphite-red';

    // ── Ink with one gesture, and the deliberate multis ───────────────────
    case RedFlick = 'red-flick';
    case SkyFlick = 'sky-flick';
    case BronzeFlick = 'bronze-flick';
    case VioletFlick = 'violet-flick';
    case Tricolore = 'tricolore';

    // ── Sweeps: one ramp across the strokes in draw order ─────────────────
    case IndigoViolet = 'indigo-violet';
    case Sunset = 'sunset';
    case Ocean = 'ocean';
    case Aurora = 'aurora';
    case Berry = 'berry';
    case Ember = 'ember';
    case Twilight = 'twilight';
    case Copper = 'copper';
    case BlueTonal = 'blue-tonal';

    /**
     * Berry, because it was chosen, not because it is first. The sweep is the
     * default face of the product now, and the PNG icon set under
     * public/icons/ is exported in it — regenerate those if this ever moves.
     */
    public const self DEFAULT = self::Berry;

    /** Near-ink and its dark-chrome counterpart, shared by the flick styles. */
    private const string INK = '#2b2620';
    private const string PAPER = '#e8e2d9';

    /**
     * The seven stroke colours, draw order, for light or dark chrome.
     *
     * @return list<string>
     */
    public function strokes(bool $dark = false): array
    {
        $palette = $this->palette();

        return $dark ? ($palette['dark'] ?? $palette['light']) : $palette['light'];
    }

    /**
     * The solid the PNG-style app icon uses behind a white mark, and what the
     * theme-color meta would honestly be for this style. One representative
     * colour per style, picked on the board.
     */
    public function tile(): string
    {
        return $this->palette()['tile'];
    }

    /**
     * @return array{light: list<string>, dark?: list<string>, tile: string}
     */
    private function palette(): array
    {
        return match ($this) {
            self::ProductBlue => ['light' => self::mono('#2563eb'), 'tile' => '#2563eb'],
            self::Ink => ['light' => self::mono(self::INK), 'dark' => self::mono(self::PAPER), 'tile' => '#2b2620'],
            self::Slate => ['light' => self::mono('#475569'), 'dark' => self::mono('#94a3b8'), 'tile' => '#475569'],
            self::Forest => ['light' => self::mono('#166534'), 'dark' => self::mono('#4ade80'), 'tile' => '#166534'],
            self::Burgundy => ['light' => self::mono('#9f1239'), 'dark' => self::mono('#fb7185'), 'tile' => '#9f1239'],
            self::DeepViolet => ['light' => self::mono('#6d28d9'), 'dark' => self::mono('#a78bfa'), 'tile' => '#6d28d9'],

            self::Postal => ['light' => self::duo('#1e3a6e', '#c8402f'), 'tile' => '#1e3a6e'],
            self::BronzeInk => ['light' => self::duo('#7d6b4f', '#3d372e'), 'dark' => self::duo('#a08a67', '#d8d0c2'), 'tile' => '#7d6b4f'],
            self::PetrolCopper => ['light' => self::duo('#0f766e', '#c2703d'), 'tile' => '#0f766e'],
            self::MidnightGold => ['light' => self::duo('#1e293b', '#d97706'), 'dark' => self::duo('#94a3b8', '#f59e0b'), 'tile' => '#1e293b'],
            self::NavySky => ['light' => self::duo('#1e3a8a', '#0ea5e9'), 'tile' => '#1e3a8a'],
            self::ForestLime => ['light' => self::duo('#166534', '#84cc16'), 'tile' => '#166534'],
            self::PlumRose => ['light' => self::duo('#86198f', '#fb7185'), 'tile' => '#86198f'],
            self::CharcoalAmber => ['light' => self::duo('#292524', '#f59e0b'), 'dark' => self::duo('#d6d3d1', '#f59e0b'), 'tile' => '#292524'],
            self::EspressoCream => ['light' => self::duo('#44403c', '#b8a385'), 'dark' => self::duo('#d6c7ae', '#8a7a5c'), 'tile' => '#44403c'],
            self::CobaltCoral => ['light' => self::duo('#1d4ed8', '#f87171'), 'tile' => '#1d4ed8'],
            self::TealGold => ['light' => self::duo('#0f766e', '#d97706'), 'tile' => '#0f766e'],
            self::GraphiteRed => ['light' => self::duo('#374151', '#dc2626'), 'dark' => self::duo('#9ca3af', '#f87171'), 'tile' => '#374151'],

            self::RedFlick => ['light' => self::flick('#c8402f'), 'dark' => self::flickDark('#e85c4a'), 'tile' => '#c8402f'],
            self::SkyFlick => ['light' => self::flick('#0284c7'), 'dark' => self::flickDark('#38bdf8'), 'tile' => '#0284c7'],
            self::BronzeFlick => ['light' => self::flick('#7d6b4f'), 'dark' => self::flickDark('#a08a67'), 'tile' => '#7d6b4f'],
            self::VioletFlick => ['light' => self::flick('#8b5cf6'), 'dark' => self::flickDark('#a78bfa'), 'tile' => '#8b5cf6'],
            self::Tricolore => ['light' => ['#1e3a6e', '#c8402f', '#1e3a6e', '#c8402f', '#d4a017', '#d4a017', '#d4a017'], 'tile' => '#1e3a6e'],

            self::IndigoViolet => ['light' => ['#4338ca', '#4f46e5', '#6366f1', '#7c3aed', '#8b5cf6', '#a855f7', '#c084fc'], 'tile' => '#4f46e5'],
            self::Sunset => ['light' => ['#e11d48', '#f43f5e', '#fb7185', '#f97316', '#fb923c', '#f59e0b', '#fbbf24'], 'tile' => '#f97316'],
            self::Ocean => ['light' => ['#1d4ed8', '#0369a1', '#0284c7', '#0891b2', '#0d9488', '#0f9f8f', '#14b8a6'], 'tile' => '#0e7490'],
            self::Aurora => ['light' => ['#16a34a', '#10b981', '#0d9488', '#0891b2', '#0284c7', '#2563eb', '#4f46e5'], 'tile' => '#0d9488'],
            self::Berry => ['light' => ['#6d28d9', '#7c3aed', '#a21caf', '#c026d3', '#db2777', '#e11d48', '#f43f5e'], 'tile' => '#a21caf'],
            self::Ember => ['light' => ['#991b1b', '#b91c1c', '#dc2626', '#ea580c', '#f97316', '#f59e0b', '#fbbf24'], 'tile' => '#dc2626'],
            self::Twilight => ['light' => ['#312e81', '#4338ca', '#6d28d9', '#9333ea', '#c026d3', '#db2777', '#f472b6'], 'tile' => '#6d28d9'],
            self::Copper => ['light' => ['#78350f', '#92400e', '#b45309', '#d97706', '#f59e0b', '#fbbf24', '#fcd34d'], 'tile' => '#b45309'],
            self::BlueTonal => ['light' => ['#1e3a8a', '#1e40af', '#1d4ed8', '#2563eb', '#3b82f6', '#60a5fa', '#7db4fa'], 'tile' => '#2563eb'],
        };
    }

    /** @return list<string> */
    private static function mono(string $hex): array
    {
        return array_fill(0, 7, $hex);
    }

    /** The p in one colour, the l in the other. @return list<string> */
    private static function duo(string $p, string $l): array
    {
        return [$p, $p, $p, $p, $l, $l, $l];
    }

    /** All ink except the l's tail. @return list<string> */
    private static function flick(string $tail): array
    {
        return [self::INK, self::INK, self::INK, self::INK, self::INK, self::INK, $tail];
    }

    /** @return list<string> */
    private static function flickDark(string $tail): array
    {
        return [self::PAPER, self::PAPER, self::PAPER, self::PAPER, self::PAPER, self::PAPER, $tail];
    }
}
