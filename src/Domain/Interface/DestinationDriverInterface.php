<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\DTO\Integration\Listing;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;

/**
 * A driver that can offer, create and vouch for the container a "Save to…"
 * lands in — a folder on a file store, an album in a photo library.
 *
 * Optional, and deliberately apart from IntegrationDriverInterface. A file
 * driver without it still saves, straight to the provider's own default, and
 * its folders can still be walked with list(); what this adds is a destination
 * *picker* — a place the user chose — and the three things such a picker owes:
 *
 *   destinations() — the containers to choose from, which is not always list().
 *   A photo library's list() at the root is its photos; the albums a save can
 *   target are a different view, and only the driver knows which.
 *
 *   assertDestination() — the guard. A chosen path or album id arrives in a
 *   request and is therefore attacker-controllable, so before a byte is
 *   uploaded the driver confirms it names a container in *this* account and
 *   refuses traversal. list() producing an id does not make a later request
 *   carrying one trustworthy.
 *
 *   createDestination() — "New folder" / "New album", where the provider makes
 *   it cheap. A folder store nests under a parent; a flat album list ignores
 *   one. Both return the new container's opaque id, ready to save into.
 */
interface DestinationDriverInterface extends IntegrationDriverInterface
{
    /**
     * The containers a save may target: folders to descend for a file store,
     * albums to pick for a photo library.
     *
     * A null folder is the top of that view — the files root, or the whole
     * album list — never the photo timeline list() would open on.
     *
     * @throws IntegrationException
     */
    public function destinations(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing;

    /**
     * Confirm $destination names a writable container this account owns, and
     * throw otherwise. The empty string is always allowed: it is the provider's
     * own default — the files root, or no album.
     *
     * @throws IntegrationException when the destination is unknown, foreign or
     *                              an attempt at traversal
     */
    public function assertDestination(Integration $integration, string $destination): void;

    /**
     * Create a container and return its opaque id.
     *
     * $parent is the folder to nest under for a tree store ('' or null is the
     * root) and is ignored by a flat album list. $name is the human name the
     * user typed.
     *
     * @throws IntegrationException
     */
    public function createDestination(Integration $integration, ?string $parent, string $name): string;
}
