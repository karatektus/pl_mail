<?php

declare(strict_types=1);

namespace App\Domain\Enum\Theme;

enum Theme: string
{
    case System = 'system';
    case Light  = 'light';
    case Paper  = 'paper';
    case Dark   = 'dark';
    case Nord   = 'nord';
    case Dusk   = 'dusk';
    case Solar  = 'solar';

    // ── One theme per logo colourway ──────────────────────────────────────
    // Every LogoStyle value is also a Theme value, verbatim — that identity
    // is what logoStyle() below trades on, and what lets a linked logo follow
    // the theme by name alone. Each carries a palette of its own in app.css:
    // seven are proper dark themes (see isDark()), the rest are light papers
    // tinted through and through in the style's mood — see the generated
    // blocks in app.css and the generator script referenced there.
    case ProductBlue = 'product-blue';
    case Ink = 'ink';
    case Slate = 'slate';
    case Forest = 'forest';
    case Burgundy = 'burgundy';
    case DeepViolet = 'deep-violet';
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
    case RedFlick = 'red-flick';
    case SkyFlick = 'sky-flick';
    case BronzeFlick = 'bronze-flick';
    case VioletFlick = 'violet-flick';
    case Tricolore = 'tricolore';
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
     * The logo colourway this theme is named after, or null for the classic
     * seven. The identity is by VALUE — a logo theme's value is exactly its
     * LogoStyle's — so this is a lookup, not a table to keep in step.
     */
    public function logoStyle(): ?LogoStyle
    {
        return LogoStyle::tryFrom($this->value);
    }

    public function isDark(): bool
    {
        return match ($this) {
            self::Dark, self::Nord, self::Dusk => true,
            // The seven logo themes whose palette blocks are dark palettes —
            // kept in step with the generated blocks in app.css by the
            // generator script referenced there.
            self::Ink, self::MidnightGold, self::CharcoalAmber,
            self::EspressoCream, self::Aurora, self::Ember, self::Twilight => true,
            default => false,
        };
    }

    public function followsSystem(): bool
    {
        return self::System === $this;
    }

    /**
     * Knob defaults seeded when this theme is selected, exactly as
     * Layout::defaults() works — picking a theme moves the controls below it,
     * and the sliders still override individually afterwards.
     *
     * The accent cannot live in the stylesheet: AppearanceRenderer writes
     * --rgb-accent inline on <html> from the user's own setting, and an inline
     * style beats any rule a [data-theme] block could carry. So a theme that
     * wants a particular accent has to seed the setting rather than restyle it.
     *
     * EVERY theme seeds one, which is the point of reading it off swatch()
     * rather than listing the two that used to. When only Paper and Dark named
     * an accent, picking either of them wrote its colour into the setting and
     * nothing ever wrote it back out: switching Paper → Nord left Nord wearing
     * Paper's clay, saved it to the account, and it survived the reload — the
     * theme picker's own swatch showed a frost blue the app never painted.
     * A default set that is the same shape for every case cannot do that,
     * because there is no case that declines to overwrite.
     *
     * swatch()[2] is the accent the picker draws for the theme, so this is the
     * colour the user was promised when they clicked it. Paper and Dark are
     * still a pair asking for a desaturated one — a default-blue #2563eb on
     * warm cream reads as a hyperlink rather than as the app's own colour, and
     * the same blue on true black is the one thing on screen that glows — but
     * that now lives in one table instead of two.
     *
     * @return array<string, scalar>
     */
    public function defaults(): array
    {
        return ['accent' => $this->swatch()[2]];
    }

    /**
     * Swatch colours for the picker — surface, ink, accent.
     *
     * The accent is also the theme's seeded setting (see defaults()), and the
     * surface and ink mirror the theme's --rgb-surface and --rgb-ink in
     * assets/styles/app.css. Three colours is enough to tell the six apart at
     * 40px, and reading them from here rather than from the stylesheet is what
     * lets the picker draw a theme it is not currently wearing.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function swatch(): array
    {
        // The logo-theme arms are GENERATED beside the palette blocks in
        // app.css (see the generator script referenced there): the surface is
        // the block's --rgb-surface, the middle dot is a secondary hue of the
        // style, and the ACCENT slot is the accent the block declares — which
        // it must be, because defaults() seeds swatch()[2] and the
        // completeness test holds the seeded accent to the block's.
        return match ($this) {
            self::System => ['#ffffff', '#111827', '#2563eb'],
            self::Light  => ['#ffffff', '#27272a', '#2563eb'],
            self::Paper  => ['#f7f5ef', '#232220', '#7d6b4f'],
            self::Dark   => ['#1c1b1a', '#d6d1ca', '#3a6f5c'],
            self::Nord   => ['#2e3440', '#eceff4', '#88c0d0'],
            self::Dusk   => ['#1e1b2e', '#ede9fe', '#a78bfa'],
            self::Solar  => ['#fdf6e3', '#586e75', '#b58900'],

            self::Ink => ['#211e1a', '#8a847a', '#e8e2d9'],
            self::MidnightGold => ['#1a2333', '#94a3b8', '#d97706'],
            self::CharcoalAmber => ['#201e1c', '#a8a29e', '#f59e0b'],
            self::EspressoCream => ['#251b13', '#8a7a5c', '#d6c7ae'],
            self::Aurora => ['#0f2027', '#0284c7', '#10b981'],
            self::Ember => ['#221713', '#fbbf24', '#ea580c'],
            self::Twilight => ['#201b38', '#8b5cf6', '#f472b6'],

            self::ProductBlue => ['#f5f8fc', '#60a5fa', '#2563eb'],
            self::Slate => ['#f2f4f6', '#94a3b8', '#475569'],
            self::Forest => ['#f3f7ee', '#4ade80', '#166534'],
            self::Burgundy => ['#faf3f1', '#fb7185', '#9f1239'],
            self::DeepViolet => ['#f6f4fa', '#a78bfa', '#6d28d9'],
            self::Postal => ['#faf5e9', '#c8402f', '#1e3a6e'],
            self::BronzeInk => ['#f5f0e4', '#3d372e', '#7d6b4f'],
            self::PetrolCopper => ['#f1f7f5', '#c2703d', '#0f766e'],
            self::NavySky => ['#f3f8fc', '#0ea5e9', '#1e3a8a'],
            self::ForestLime => ['#f4f8ea', '#166534', '#84cc16'],
            self::PlumRose => ['#faf2f6', '#fb7185', '#86198f'],
            self::CobaltCoral => ['#f5f7fc', '#f87171', '#1d4ed8'],
            self::TealGold => ['#f9f4e6', '#d97706', '#0f766e'],
            self::GraphiteRed => ['#f4f4f5', '#374151', '#dc2626'],
            self::RedFlick => ['#f9f5f0', '#2b2620', '#c8402f'],
            self::SkyFlick => ['#f5f8fa', '#2b2620', '#0284c7'],
            self::BronzeFlick => ['#f8f4ee', '#2b2620', '#7d6b4f'],
            self::VioletFlick => ['#f7f6fb', '#2b2620', '#8b5cf6'],
            self::Tricolore => ['#fbf6e8', '#d4a017', '#c8402f'],
            self::IndigoViolet => ['#f4f4fb', '#a855f7', '#4f46e5'],
            self::Sunset => ['#fdf6ec', '#fbbf24', '#e11d48'],
            self::Ocean => ['#f1f8f9', '#14b8a6', '#0e7490'],
            self::Berry => ['#faf2f8', '#e11d48', '#a21caf'],
            self::Copper => ['#faf1e7', '#f59e0b', '#b45309'],
            self::BlueTonal => ['#f2f5fa', '#60a5fa', '#1e40af'],
        };
    }
}
