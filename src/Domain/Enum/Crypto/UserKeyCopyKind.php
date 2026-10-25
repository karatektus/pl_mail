<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * What a recovery copy of a user key is wrapped with.
 *
 * A phrase is twelve words the person keeps somewhere safe; there is at most
 * one per user key, and making a new one replaces it. A device is a browser
 * the person signed in on, which holds the other half in its own storage and
 * can open the key again without being asked anything — the comfortable way
 * back after a password reset.
 */
enum UserKeyCopyKind: string
{
    case Phrase = 'phrase';
    case Device = 'device';
}
