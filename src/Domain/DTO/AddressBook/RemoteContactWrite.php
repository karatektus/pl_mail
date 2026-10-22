<?php

declare(strict_types=1);

namespace App\Domain\DTO\AddressBook;

/**
 * What a driver's put() reports back: where the contact now lives and its new
 * version, plus what to keep for the next write — the vCard that was sent
 * (CardDAV), or the driver's opaque resource (Google, Graph). AddressBookSync
 * writes these onto the Contact; the driver touches no entity state.
 */
final readonly class RemoteContactWrite
{
    public function __construct(
        public string  $href,
        public ?string $etag,
        public ?string $vcard = null,
        public ?string $resource = null,
    ) {
    }
}
