<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\DTO\Integration\Listing;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration;

/**
 * A driver that can find files by text query, not only by walking folders.
 *
 * Deliberately separate from IntegrationDriverInterface. Search is genuinely
 * optional — a WebDAV share has nothing to offer here — and folding it into the
 * main interface would force every driver to carry a stub method that throws.
 * Capability::Search is what the UI checks; this is what the controller calls,
 * and the two are kept in step by the driver declaring both or neither.
 */
interface SearchableDriverInterface
{
    /**
     * Matches for a query, newest or most-relevant first as the service sees fit.
     *
     * $folderId is where the user was searching from, because "search" can mean
     * different things in different views: in Immich's people view the box
     * filters faces by name, everywhere else it searches photos. Passing the
     * context lets one search box serve both instead of the UI needing two.
     *
     * Results are otherwise a flat list — an asset search crosses albums by
     * definition, so the listing's breadcrumb describes the search rather than a
     * location.
     *
     * @throws IntegrationException
     */
    public function search(
        Integration $integration,
        string $query,
        ?string $folderId = null,
        ?string $cursor = null,
    ): Listing;
}
