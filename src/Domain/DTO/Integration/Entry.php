<?php

declare(strict_types=1);

namespace App\Domain\DTO\Integration;

/**
 * One row in a picker listing — a folder to descend into or a file to attach.
 *
 * `id` is whatever the driver needs to address the thing again and is opaque
 * everywhere else: a WebDAV path for Nextcloud, a UUID for Immich. It travels
 * back to the driver untouched, so nothing outside the driver may parse it.
 */
final readonly class Entry
{
    public function __construct(
        public string             $id,
        public string             $name,
        public bool               $isFolder,
        public ?int               $size = null,
        public ?string            $mime = null,
        public ?\DateTimeImmutable $modifiedAt = null,
        /** Absolute URL of a thumbnail, where the service offers one cheaply. */
        public ?string            $thumbnailUrl = null,
    ) {
    }

    public static function folder(string $id, string $name): self
    {
        return new self($id, $name, true);
    }
}
