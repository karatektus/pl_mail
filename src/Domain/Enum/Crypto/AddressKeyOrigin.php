<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * Where an address key came from.
 *
 * Kept because the two are treated differently: a generated key never
 * expires, an imported one may carry an expiry the person chose elsewhere and
 * is warned about. And a generated key that was never used is replaced, not
 * kept, when its owner imports the key their contacts already know.
 */
enum AddressKeyOrigin: string
{
    case Generated = 'generated';
    case Imported  = 'imported';
}
