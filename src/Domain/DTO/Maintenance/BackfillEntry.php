<?php

declare(strict_types=1);

namespace App\Domain\DTO\Maintenance;

use App\Entity\Maintenance\BackfillRun;
use DateTimeImmutable;

/**
 * One backfill as Admin → Backfills lists it: the task's facts, and what this
 * installation has done with it.
 *
 * The translation keys are built from the name here rather than in the
 * template, because a task name carries dashes and a key does not, and that
 * rewriting should happen in one place. BackfillTasksTest reads the
 * same property to check every task has its text.
 */
final readonly class BackfillEntry
{
    public string $translationKey;

    public function __construct(
        public string             $name,
        public DateTimeImmutable  $addedOn,
        public bool               $windowed,
        public bool               $countable,
        public bool               $safeToRunAgain,
        public bool               $isNew,
        public ?BackfillRun       $latestRun,
    ) {
        $this->translationKey = sprintf('admin.backfills.task.%s', str_replace('-', '_', $name));
    }
}
