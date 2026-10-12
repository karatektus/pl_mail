<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * Where plMail found a contact's public key.
 *
 * Shown beside the key, because how far a key deserves belief starts with
 * where it came from: a key the recipient's own domain publishes (WKD) is the
 * domain's word for it, one that arrived with a message — in an Autocrypt
 * header or attached — is only the word of whoever sent that message, and one
 * the person imported is theirs. None of them is a verification; only pinning
 * is (see ContactKey::$pinned).
 *
 * No `vks` (keys.openpgp.org) yet: that lookup is an instance setting the
 * concept leaves optional, and an enum case nothing writes would only be a
 * promise.
 */
enum ContactKeyOrigin: string
{
    case Wkd       = 'wkd';
    case Attached  = 'attached';
    case Autocrypt = 'autocrypt';
    case Manual    = 'manual';
}
