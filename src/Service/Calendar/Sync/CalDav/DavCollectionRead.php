<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\CalDav;

/**
 * What one read of a collection came back with: a complete window of changes and
 * the position after it, or the news that the position is no good.
 */
final readonly class DavCollectionRead
{
    /**
     * @param list<DavChange> $changes
     */
    public function __construct(
        public array   $changes = [],
        public ?string $nextSyncToken = null,
        public bool    $requiresFullResync = false,
    ) {}

    public static function resyncRequired(): self
    {
        return new self(requiresFullResync: true);
    }
}
