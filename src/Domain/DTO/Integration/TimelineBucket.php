<?php

declare(strict_types=1);

namespace App\Domain\DTO\Integration;

/**
 * One period of a photo library's timeline, and how much is in it.
 *
 * This is what lets a scrubber be honest about a library it has not loaded.
 * Counts come from the service in one cheap call, so the bar can size each
 * month proportionally and jump straight to 2019 without ever having fetched a
 * photo from it — a bar derived from loaded pages instead would misrepresent the
 * library's shape until the user had scrolled all of it, which is worse than
 * having no bar.
 *
 * `cursor` is the opaque value to hand back as a listing cursor to land here.
 * Only the driver knows how to build one.
 */
final readonly class TimelineBucket
{
    public function __construct(
        public string $cursor,
        /** Short label for the bar, e.g. "2026" or "Jul". */
        public string $label,
        public int    $count,
        /** Full label for the hover readout, e.g. "July 2026". */
        public string $title,
    ) {
    }
}
