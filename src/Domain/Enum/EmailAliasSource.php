<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Where an alias came from. Purely informational — it never gates behaviour,
 * only lets the UI label rows and lets the seeder avoid clobbering manual work.
 *
 *  - Provider : discovered from the provider (Graph profile emails, etc.).
 *  - Manual   : typed in by the user.
 *  - System   : a provider-internal canonical address (e.g. the immutable
 *               outlook_…@outlook.com backing address) — real and deliverable,
 *               but not something to show as a friendly primary.
 */
enum EmailAliasSource: string
{
    case Provider = 'provider';
    case Manual   = 'manual';
    case System   = 'system';
}
