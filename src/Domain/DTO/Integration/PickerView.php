<?php

declare(strict_types=1);

namespace App\Domain\DTO\Integration;

/**
 * One render of the file picker: what the service answered, and what the picker
 * is allowed to offer alongside it.
 *
 * The capability flags travel with the listing rather than being asked for
 * separately, because they are decided by the same two facts — what the user
 * enabled and what the driver implements — and answering one without the other
 * is how a search box appears above a driver that cannot search.
 *
 * A failed listing is an error string and no entries, not an exception: the
 * picker still renders, with the reason where the files would have been.
 */
final readonly class PickerView
{
    /**
     * @param list<TimelineBucket> $buckets
     */
    public function __construct(
        public ?Listing $listing,
        public ?string  $error,
        public bool     $canSearch,
        public array    $buckets,
    ) {
    }
}
