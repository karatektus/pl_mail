<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * Where plMail found a contact's public key.
 *
 * Shown beside the key, because how far a key deserves belief starts with
 * where it came from: a key the recipient's own domain publishes (WKD) is the
 * domain's word for it; one from a public key server is the word of whoever
 * proved to that server that they read mail at the address — which is what
 * keys.openpgp.org and Mailvelope check before they hand an address out, and
 * no more; one that arrived with a message — in an Autocrypt header or
 * attached — is only the word of whoever sent that message, and one the
 * person imported is theirs. None of them is a verification; only pinning is
 * (see ContactKey::$pinned).
 *
 * KeyServer is one case for every server, with the server's host on the row
 * (ContactKey::$originHost): the servers differ in name, not in what their
 * word is worth, and a case per server would make adding one a schema change.
 */
enum ContactKeyOrigin: string
{
    case Wkd       = 'wkd';
    case KeyServer = 'keyserver';
    case Attached  = 'attached';
    case Autocrypt = 'autocrypt';
    case Manual    = 'manual';
}
