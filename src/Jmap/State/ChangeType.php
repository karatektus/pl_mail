<?php

declare(strict_types=1);

namespace App\Jmap\State;

enum ChangeType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Destroyed = 'destroyed';
}
