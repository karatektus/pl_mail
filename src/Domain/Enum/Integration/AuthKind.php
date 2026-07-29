<?php

declare(strict_types=1);

namespace App\Domain\Enum\Integration;

/**
 * How a user proves who they are to a service, which decides what the connect
 * form asks for and whether the provider needs admin-side registration at all.
 */
enum AuthKind: string
{
    /**
     * The user pastes a credential they generated themselves — a Nextcloud app
     * password, an Immich API key. Nothing is registered application-side, so
     * an admin only has to enable the provider (and optionally pin a base URL)
     * for it to work.
     */
    case AppPassword = 'appPassword';

    /**
     * The application is registered with the service and holds a client id and
     * secret; the user authorises it and we keep their tokens. Unusable until
     * an admin has filled in those credentials, which is what the setup
     * tutorials exist to walk them through.
     */
    case OAuth2 = 'oauth2';
}
