<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * What one of a person's own S/MIME certificates is still good for.
 *
 * An active certificate signs, and is what mail to its addresses is encrypted
 * to. A retired one was replaced by a newer certificate for the same
 * addresses — S/MIME certificates expire every year or two, and the renewal is
 * a new certificate with a new key — and still decrypts the mail that was
 * encrypted to it. Nothing is ever signed or encrypted with a retired one.
 */
enum SmimeCertificateStatus: string
{
    case Active  = 'active';
    case Retired = 'retired';
}
