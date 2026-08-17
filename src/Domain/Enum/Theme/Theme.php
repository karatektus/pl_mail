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
    // the theme by name alone. All thirty-two are LIGHT themes: Paper's
    // surfaces and ink with the style's tile as accent and a whisper of it in
    // the page background (see the generated blocks in app.css).
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
     * The paper base the logo themes tint — the flat page colour Paper's own
     * --app-bg paints (see :root[data-theme="paper"] in app.css).
     */
    private const string PAPER_BG = '#f2efe7';

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
            default                            => false,
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
        // A logo theme's swatch is derived from its style, the same three
        // slots the classic themes fill: the surface is the soft tile-tinted
        // page the CSS block paints, the middle dot is the mark's own
        // mid-stroke, and the ACCENT slot is the tile — which has to agree
        // with the --rgb-accent the generated block declares, because
        // defaults() seeds swatch()[2] and the completeness test holds the
        // seeded accent to the block's.
        if (null !== $style = $this->logoStyle()) {
            return [self::softTint($style->tile()), $style->strokes()[4], $style->tile()];
        }

        return match ($this) {
            self::System => ['#ffffff', '#111827', '#2563eb'],
            self::Light  => ['#ffffff', '#27272a', '#2563eb'],
            self::Paper  => ['#f7f5ef', '#232220', '#7d6b4f'],
            self::Dark   => ['#1c1b1a', '#d6d1ca', '#3a6f5c'],
            self::Nord   => ['#2e3440', '#eceff4', '#88c0d0'],
            self::Dusk   => ['#1e1b2e', '#ede9fe', '#a78bfa'],
            self::Solar  => ['#fdf6e3', '#586e75', '#b58900'],
            default      => ['#ffffff', '#111827', '#2563eb'],
        };
    }

    /**
     * 7% of the tile mixed into the paper page — the same arithmetic the
     * generator script used for the --app-bg of every logo theme's block, so
     * the picker's tile shows the page the theme will actually paint.
     */
    private static function softTint(string $tile): string
    {
        $channel = static fn (string $hex, int $slot): int => (int) hexdec(substr($hex, 1 + 2 * $slot, 2));
        $mix     = static fn (int $paper, int $ink): int => (int) round($paper + 0.07 * ($ink - $paper));

        return sprintf(
            '#%02x%02x%02x',
            $mix($channel(self::PAPER_BG, 0), $channel($tile, 0)),
            $mix($channel(self::PAPER_BG, 1), $channel($tile, 1)),
            $mix($channel(self::PAPER_BG, 2), $channel($tile, 2)),
        );
    }
}
