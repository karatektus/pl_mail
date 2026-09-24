<?php

declare(strict_types=1);

namespace App\Domain\DTO\System;

/**
 * GitHub's answer to "how does the channel's build relate to this one?".
 */
final readonly class SourceComparison
{
    /**
     * @param string                                     $status  ahead, behind, identical or diverged, as GitHub
     *                                                            spells them, seen from the running build
     * @param list<array{sha: string, subject: string}> $changes newest first
     */
    public function __construct(
        public string  $status,
        public int     $aheadBy,
        public array   $changes,
        public ?string $url,
    ) {
    }

    /** The channel has commits this build does not: an update, whatever else is true. */
    public function hasNewer(): bool
    {
        return in_array($this->status, ['ahead', 'diverged'], true) && $this->aheadBy > 0;
    }
}
