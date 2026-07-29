<?php

declare(strict_types=1);

namespace App\Domain\DTO\Integration;

/**
 * One page of a folder's contents, plus the breadcrumb that led here.
 *
 * The breadcrumb is built by the driver rather than accumulated in the URL:
 * only the driver knows how to turn its own opaque folder id back into a
 * readable path, and carrying the trail in the query string would let a user
 * hand-edit their way into an inconsistent view.
 */
final readonly class Listing
{
    /**
     * @param list<Entry> $entries
     * @param list<Entry> $breadcrumb root first, current folder last
     */
    public function __construct(
        public array   $entries,
        public array   $breadcrumb = [],
        public ?string $nextCursor = null,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /** @return list<Entry> */
    public function folders(): array
    {
        return array_values(array_filter($this->entries, static fn (Entry $e): bool => $e->isFolder));
    }

    /** @return list<Entry> */
    public function files(): array
    {
        return array_values(array_filter($this->entries, static fn (Entry $e): bool => false === $e->isFolder));
    }
}
