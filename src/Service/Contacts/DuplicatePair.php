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
        public Contact $a,
        public Contact $b,
        public int $confidence,
        /** @var list<string> */
        public array $reasons,
        public bool $autoMergeable,
    ) {
    }
}
