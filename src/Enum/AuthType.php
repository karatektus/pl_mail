<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How credentials for an {@see \App\Entity\Account} are presented to the server.
 */
enum AuthType: string
{
    case Password = 'password';
    case OAuth2 = 'oauth2';
}
