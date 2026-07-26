<?php

declare(strict_types=1);

namespace App\Jmap\State;

/**
 * The computed result of a "/changes" call: the collapsed created / updated /
 * destroyed id partitions between two state tokens. Feeds directly into
 * Mailbox/changes and Email/changes once those methods land.
 */
final class ChangeSet
{
    /**
     * @param list<string> $created
     * @param list<string> $updated
     * @param list<string> $destroyed
     */
    public function __construct(
        public readonly string $oldState,
        public readonly string $newState,
        public readonly bool $hasMoreChanges,
        public readonly array $created,
        public readonly array $updated,
        public readonly array $destroyed,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toResult(string $accountId): array
    {
        return [
            'accountId' => $accountId,
            'oldState' => $this->oldState,
            'newState' => $this->newState,
            'hasMoreChanges' => $this->hasMoreChanges,
            'created' => $this->created,
            'updated' => $this->updated,
            'destroyed' => $this->destroyed,
        ];
    }
}
