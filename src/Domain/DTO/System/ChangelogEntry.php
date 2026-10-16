<?php

declare(strict_types=1);

namespace App\Domain\DTO\System;

/**
 * One change as CHANGELOG.md tells it, ready to show (#165).
 *
 * The headline and body are HTML already, rendered from the changelog's own
 * Markdown by ChangelogMarkdown: escaped first, then a fixed handful of inline
 * forms put back. Safe to print with `|raw`, and nothing else in plMail builds
 * them.
 */
final readonly class ChangelogEntry
{
    public function __construct(
        /** The bold first sentence, as inline HTML. */
        public string  $headline,
        /** Everything after it, as block HTML; '' for a one-line entry. */
        public string  $body,
        /** Marked `<!-- highlight -->` above it in the changelog. */
        public bool    $highlight = false,
        /** The name a highlight's screenshot was given, `pull-to-sync`; null without one. */
        public ?string $screenshot = null,
    ) {
    }
}
