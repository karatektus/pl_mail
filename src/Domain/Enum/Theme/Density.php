<?php

declare(strict_types=1);

namespace App\Domain\Enum\Theme;

enum Density: string
{
    case Comfortable = 'comfortable';
    case Cosy        = 'cosy';
    case Compact     = 'compact';

    public function rowPadding(): string
    {
        return match ($this) {
            self::Comfortable => '0.875rem',
            self::Cosy        => '0.625rem',
            self::Compact     => '0.375rem',
        };
    }

    public function gap(): string
    {
        return match ($this) {
            self::Comfortable => '0.75rem',
            self::Cosy        => '0.5rem',
            self::Compact     => '0.375rem',
        };
    }
}
