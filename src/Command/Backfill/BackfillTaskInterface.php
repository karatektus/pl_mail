<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use DateTimeImmutable;
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

    /**
     * The day the task was written.
     *
     * Admin → Backfills lists the newest first, because the newest are the ones
     * an update most likely asks for: a backfill repairs what a fix could not
     * reach, and the fix that needs it is usually the latest one. Declared here
     * rather than read from git, because the image carries no history.
     */
    public function getAddedOn(): DateTimeImmutable;

    /**
     * Whether a second run, after the first has finished, does no harm.
     *
     * Most repairs select only the rows that still have the fault, or
     * rebuild from an untouched original, so a second run arrives where the
     * first one did. A few do not — one counts again, one throws away what
     * was made since — and Admin → Backfills tells an administrator which
     * before they press Run, with the reason (`…repeat_warning` in the
     * translations; BackfillTasksTest checks it is there).
     *
     * A method every task must answer, rather than an interface the safe
     * ones opt into: forgetting to declare it should be a compile error, not
     * a task the page quietly calls safe.
     */
    public function isSafeToRunAgain(): bool;

    /** Returns a Command exit code (0 = success). */
    public function run(SymfonyStyle $io): int;
}
