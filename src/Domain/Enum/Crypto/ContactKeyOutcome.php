<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * What offering a key for an address to ContactKeys did.
 *
 * Added: the address had no key, and this one is now in use. Seen: plMail
 * already had this very key for the address. Changed: the address has a key
 * already, and this different one was recorded beside it for the person to
 * decide about. Unusable: revoked, expired, or without a user ID for the
 * address — not kept at all.
 */
enum ContactKeyOutcome: string
{
    case Added    = 'added';
    case Seen     = 'seen';
    case Changed  = 'changed';
    case Unusable = 'unusable';
}
