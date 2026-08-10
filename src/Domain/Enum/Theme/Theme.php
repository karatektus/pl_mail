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
        return match ($this) {
            self::System => ['#ffffff', '#111827', '#2563eb'],
            self::Light  => ['#ffffff', '#27272a', '#2563eb'],
            self::Paper  => ['#f7f5ef', '#232220', '#7d6b4f'],
            self::Dark   => ['#1c1b1a', '#d6d1ca', '#3a6f5c'],
            self::Nord   => ['#2e3440', '#eceff4', '#88c0d0'],
            self::Dusk   => ['#1e1b2e', '#ede9fe', '#a78bfa'],
            self::Solar  => ['#fdf6e3', '#586e75', '#b58900'],
        };
    }
}
