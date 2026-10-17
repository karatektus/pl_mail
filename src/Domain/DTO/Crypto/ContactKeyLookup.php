<?php

declare(strict_types=1);

namespace App\Domain\DTO\Crypto;

use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyOutcome;

/**
 * What looking an address's key up did (App\Service\Crypto\ContactKeys
 * ::lookUp()): what became of the key, and where it was found — the domain's
 * own Web Key Directory, or a key server, by name. Every place that offers a
 * lookup says where the key came from, because how far it deserves belief
 * starts there.
 */
final readonly class ContactKeyLookup
{
    public function __construct(
        public ContactKeyOutcome $outcome,
        public ContactKeyOrigin  $origin,
        public ?string           $host = null,
    ) {}
}
