<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * What checking a signed message found.
 *
 * Valid: the signature holds, and the key or certificate behind it is one
 * plMail has reason to believe belongs to the sender. Untrusted: it holds, but
 * nothing vouches for the signer — an S/MIME certificate no known authority
 * issued, a PGP key nobody has confirmed. Invalid: the message was changed
 * after it was signed, or the signature names somebody other than the sender.
 * UnknownKey: a PGP signature whose key plMail does not have yet, so there is
 * nothing to check it against.
 *
 * Only Invalid is a warning. The others are degrees of how much the lock can
 * say, and the page says it in those words.
 */
enum SignatureState: string
{
    case Valid      = 'valid';
    case Untrusted  = 'untrusted';
    case Invalid    = 'invalid';
    case UnknownKey = 'unknown_key';
}
