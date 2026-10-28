<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

enum ImapAuthMechanism: string
{
    /** The LOGIN command: a username and a password. */
    case Login = 'login';

    /** SASL XOAUTH2, as Google and Microsoft take an OAuth access token. */
    case XOAuth2 = 'xoauth2';
}
