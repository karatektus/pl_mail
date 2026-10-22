<?php

declare(strict_types=1);

namespace App\Domain\DTO\System;

use DateTimeImmutable;

/**
 * One `## vX.Y.Z — date` section of CHANGELOG.md (#165).
 *
 * `Unreleased` never becomes one of these: what has not been tagged is not
 * something a running install has, whatever the file in it says.
 */
final readonly class ChangelogRelease
{
    /**
     * @param list<ChangelogEntry> $entries
     * @param list<string>         $adminNotes block HTML: migration and "before you upgrade" notes
     */
    public function __construct(
        /** Without the `v`: `0.3.6`, ready for version_compare(). */
        public string             $version,
        public ?DateTimeImmutable $date,
        public array              $entries,
        public array              $adminNotes = [],
        /** Block HTML that came before the first entry, '' when there was none. */
        public string             $intro = '',
    ) {
    }

    /** As the changelog and the image tag write it. */
    public function tag(): string
    {
        return 'v' . $this->version;
    }

    /** @return list<ChangelogEntry> */
    public function highlights(): array
    {
        return array_values(array_filter($this->entries, static fn (ChangelogEntry $entry): bool => $entry->highlight));
    }

    /** @return list<ChangelogEntry> */
    public function others(): array
    {
        return array_values(array_filter($this->entries, static fn (ChangelogEntry $entry): bool => false === $entry->highlight));
    }
}
