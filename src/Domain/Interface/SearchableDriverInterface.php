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
     * Files matching a query, newest or most-relevant first as the service sees
     * fit. Results are a flat list — search crosses folders by definition, so
     * the listing's breadcrumb describes the search rather than a location.
     *
     * @throws IntegrationException
     */
    public function search(Integration $integration, string $query, ?string $cursor = null): Listing;
}
