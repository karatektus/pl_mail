<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use App\Entity\Contacts\Contact;

/**
 * One suspected duplicate pair, produced by DuplicateFinder.
 */
final class DuplicatePair
{
    public function __construct(
        public readonly Contact $a,
        public readonly Contact $b,
        public readonly int $confidence,
        /** @var list<string> */
        public readonly array $reasons,
        public readonly bool $autoMergeable,
    ) {
    }
}
