<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * What an address key is still good for.
 *
 * Proton's three states, and for the same reasons. An obsolete key was
 * replaced — renewed, or superseded by an imported one — and still decrypts
 * the mail that was encrypted to it. A compromised key decrypts too, because
 * the mail is still there, but nothing it signed is shown as verified any
 * more, and its revocation certificate is published. Only an active key is
 * ever offered for encrypting to or signing with.
 */
enum AddressKeyStatus: string
{
    case Active      = 'active';
    case Obsolete    = 'obsolete';
    case Compromised = 'compromised';
}
