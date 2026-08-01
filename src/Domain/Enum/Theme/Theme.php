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
     * Only the accent, and only where the palette actually needs one. The
     * accent cannot live in the stylesheet: AppearanceRenderer writes
     * --rgb-accent inline on <html> from the user's own setting, and an inline
     * style beats any rule a [data-theme] block could carry. So a theme that
     * wants a particular accent has to seed the setting rather than restyle it.
     *
     * Paper and Dark are a pair, and both ask for a desaturated accent: a
     * default-blue #2563eb on warm cream reads as a hyperlink rather than as
     * the app's own colour, and the same blue on true black is the one thing
     * on screen that glows.
     *
     * @return array<string, scalar>
     */
    public function defaults(): array
    {
        return match ($this) {
            self::Paper => ['accent' => '#7d6b4f'],
            self::Dark  => ['accent' => '#8ab4a0'],
            default     => [],
        };
    }

    /** Swatch colours for the picker — surface, ink, accent. */
    public function swatch(): array
    {
        return match ($this) {
            self::System => ['#ffffff', '#111827', '#2563eb'],
            self::Light  => ['#ffffff', '#27272a', '#2563eb'],
            self::Paper  => ['#f7f5ef', '#232220', '#7d6b4f'],
            self::Dark   => ['#121212', '#e8e6e1', '#8ab4a0'],
            self::Nord   => ['#2e3440', '#eceff4', '#88c0d0'],
            self::Dusk   => ['#1e1b2e', '#ede9fe', '#a78bfa'],
            self::Solar  => ['#fdf6e3', '#586e75', '#b58900'],
        };
    }
}
