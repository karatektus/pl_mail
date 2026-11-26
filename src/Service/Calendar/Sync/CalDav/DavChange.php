<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\CalDav;

/**
 * One resource a collection reported, before anyone has decided what it holds.
 *
 * A null $data is a tombstone. Not a Domain DTO, for the reason CalDavEndpoint is
 * not: it never leaves this protocol, and each driver turns it into its own shape.
 */
final readonly class DavChange
{
    public function __construct(
        public string  $href,
        public ?string $etag,
        public ?string $data,
    ) {}

    public function isDeleted(): bool
    {
        return null === $this->data;
    }
}
