<?php

declare(strict_types=1);

namespace App\Domain\DTO\System;

use DateTimeImmutable;

/**
 * One image as the registry publishes it, read off its own labels.
 */
final readonly class PublishedBuild
{
    public function __construct(
        /** The tag it was built for: `v0.2.45`, or `main` for a branch build. */
        public string             $version,
        /** The full commit it was built from. */
        public string             $revision,
        public ?DateTimeImmutable $builtAt,
        /** The repository it says it came from, e.g. https://github.com/karatektus/pl_mail. */
        public ?string            $source,
    ) {
    }

    public function shortRevision(): string
    {
        return substr($this->revision, 0, 7);
    }

    /** A release names itself; a branch build is only told apart by its commit. */
    public function isRelease(): bool
    {
        return 1 === preg_match('/^v?\d+\.\d+\.\d+$/', $this->version);
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'version'  => $this->version,
            'revision' => $this->revision,
            'builtAt'  => $this->builtAt?->format(DATE_ATOM),
            'source'   => $this->source,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $version  = $data['version'] ?? null;
        $revision = $data['revision'] ?? null;

        if (false === is_string($version) || false === is_string($revision) || '' === $revision) {
            return null;
        }

        $builtAt = is_string($data['builtAt'] ?? null) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $data['builtAt']) : false;
        $source  = $data['source'] ?? null;

        return new self($version, $revision, false === $builtAt ? null : $builtAt, is_string($source) ? $source : null);
    }
}
