<?php

declare(strict_types=1);

namespace App\Domain\Enum\Theme;

enum BackgroundPreset: string
{
    case Aurora   = 'aurora';
    case Dunes    = 'dunes';
    case Fog      = 'fog';
    case Granite  = 'granite';
    case Harbour  = 'harbour';
    case Linen    = 'linen';
    case Pine     = 'pine';
    case Tide     = 'tide';

    public function file(): string
    {
        return sprintf('images/backgrounds/%s.jpg', $this->value);
    }

    public function thumbnail(): string
    {
        return sprintf('images/backgrounds/%s-thumb.jpg', $this->value);
    }
}
