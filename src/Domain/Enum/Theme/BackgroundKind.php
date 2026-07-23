<?php

declare(strict_types=1);

namespace App\Domain\Enum\Theme;

enum BackgroundKind: string
{
    case Theme  = 'theme';
    case Preset = 'preset';
    case Custom = 'custom';
    case Solid  = 'solid';
}
