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
 *
 * Shortcuts are sideways jumps a driver wants offered next to the results —
 * Immich surfacing "Albums" from its timeline, for instance. They exist so a
 * service's own shape can reach the UI without the template growing a
 * per-provider branch: the picker renders whatever chips it is handed.
 */
final readonly class Listing
{
    /**
     * @param list<Entry> $entries
     * @param list<Entry> $breadcrumb root first, current folder last
     * @param list<Entry> $shortcuts  jumps offered alongside the results
     */
    public function __construct(
        public array   $entries,
        public array   $breadcrumb = [],
        public ?string $nextCursor = null,
        public array   $shortcuts = [],
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

    /**
     * Recognised people, which render as named portraits rather than folder rows.
     *
     * A named helper rather than the template reaching into the first entry's
     * kind: that read the raw property, which is null for everything except a
     * person, and blew up on every ordinary listing.
     *
     * @return list<Entry>
     */
    public function people(): array
    {
        return array_values(array_filter($this->entries, static fn (Entry $e): bool => $e->isPerson()));
    }
}
