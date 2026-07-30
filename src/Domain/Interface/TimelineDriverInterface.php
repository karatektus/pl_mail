<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\DTO\Integration\TimelineBucket;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration;

/**
 * A driver whose library can be summarised as a chronological histogram.
 *
 * Separate from IntegrationDriverInterface for the same reason
 * SearchableDriverInterface is: a file store has no meaningful timeline, and
 * folding this in would force five drivers to carry a method that throws.
 *
 * Capability::Timeline is what the UI checks before drawing a scrubber; this is
 * what the controller calls.
 */
interface TimelineDriverInterface
{
    /**
     * Newest first, one entry per period, each carrying the cursor that lands on
     * it.
     *
     * Must be one cheap call. The whole point is knowing the shape of a library
     * without paging through it, so an implementation that walked the assets to
     * count them would defeat the feature it exists to serve.
     *
     * An empty list is a valid answer — an empty library, or a service that
     * cannot summarise cheaply — and the UI simply draws no scrubber.
     *
     * @return list<TimelineBucket>
     *
     * @throws IntegrationException
     */
    public function timelineBuckets(Integration $integration): array;
}
