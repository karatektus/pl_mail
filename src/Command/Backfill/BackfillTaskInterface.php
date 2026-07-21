<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A single, self-contained backfill unit. Each task owns its own iteration and
 * batching — different tasks touch different entities, so there is no shared
 * loop to factor out.
 */
#[AutoconfigureTag('app.backfill_task')]
interface BackfillTaskInterface
{
    /** CLI key, e.g. "safe-html". */
    public function getName(): string;

    /** One-line description shown in the task listing. */
    public function getDescription(): string;

    /** Returns a Command exit code (0 = success). */
    public function run(SymfonyStyle $io): int;
}
