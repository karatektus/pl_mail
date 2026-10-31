<?php

declare(strict_types=1);

namespace App\Domain\DTO\Contacts;

/**
 * What changed since a state, as ContactCard/changes and AddressBook/changes
 * report it: the three partitions, each a list of ids.
 *
 * @see \App\Service\Contacts\Change\ContactChangeReader
 */
final readonly class ContactDelta
{
    /**
     * @param list<string> $created
     * @param list<string> $updated
     * @param list<string> $destroyed
     */
    public function __construct(
        public string $oldState,
        public string $newState,
        public bool $hasMoreChanges,
        public array $created,
        public array $updated,
        public array $destroyed,
    ) {
    }
}
