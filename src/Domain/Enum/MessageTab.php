<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum MessageTab: string
{
    case Primary = 'primary';
    case Promotions = 'promotions';
    case Updates = 'updates';
    case Social = 'social';
}
