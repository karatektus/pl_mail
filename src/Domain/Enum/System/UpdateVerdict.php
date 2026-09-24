<?php

declare(strict_types=1);

namespace App\Domain\Enum\System;

/**
 * What the last answered update check concluded. Stored inside the check's
 * status document, so the values are persisted.
 */
enum UpdateVerdict: string
{
    /** The channel has nothing this build does not already have. */
    case Current = 'current';

    /** The channel has a newer build than this one. */
    case Available = 'available';

    /**
     * The channel's newest build is known, but not how it relates to this one:
     * a build with no commit stamped in, or two builds whose history there was
     * no way to ask about.
     */
    case Unknown = 'unknown';
}
