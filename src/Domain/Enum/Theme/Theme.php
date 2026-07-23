<?php

declare(strict_types=1);

namespace App\Domain\Enum\Theme;

enum Theme: string
{
    case System = 'system';
    case Light  = 'light';
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

    /** Swatch colours for the picker — surface, ink, accent. */
    public function swatch(): array
    {
        return match ($this) {
            self::System => ['#ffffff', '#111827', '#2563eb'],
            self::Light  => ['#ffffff', '#27272a', '#2563eb'],
            self::Dark   => ['#111827', '#f4f4f5', '#3b82f6'],
            self::Nord   => ['#2e3440', '#eceff4', '#88c0d0'],
            self::Dusk   => ['#1e1b2e', '#ede9fe', '#a78bfa'],
            self::Solar  => ['#fdf6e3', '#586e75', '#b58900'],
        };
    }
}
