<?php

declare(strict_types=1);

namespace App\Domain\DTO\Integration;

use App\Domain\Enum\Integration\EntryKind;

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
        /**
         * Only set where "folder or file" loses something the UI needs — a
         * recognised person, say. Left null by every driver that deals purely in
         * files and folders.
         */
        public ?EntryKind         $kind = null,
    ) {
    }

    public static function folder(string $id, string $name): self
    {
        return new self($id, $name, true);
    }

    /**
     * A person: navigable like a folder, but rendered as a named portrait.
     */
    public static function person(string $id, string $name): self
    {
        return new self($id, $name, true, kind: EntryKind::Person);
    }

    /**
     * The declared kind, or the obvious one. Templates read this rather than
     * isFolder so a new kind does not need every branch revisited.
     */
    public function kind(): EntryKind
    {
        return $this->kind ?? ($this->isFolder ? EntryKind::Folder : EntryKind::File);
    }
}
