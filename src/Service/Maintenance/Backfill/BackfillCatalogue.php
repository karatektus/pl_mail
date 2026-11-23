<?php

declare(strict_types=1);

namespace App\Service\Maintenance\Backfill;

use App\Command\Backfill\CountableBackfillTaskInterface;
use App\Command\Backfill\WindowedBackfillTaskInterface;
use App\Domain\DTO\Maintenance\BackfillEntry;
use App\Repository\Maintenance\BackfillRun\BackfillRunRepository;
use DateTimeImmutable;

/**
 * The list Admin → Backfills shows: every task, newest first, with its last
 * run here and whether it is new.
 *
 * **New** means added in the last NEW_DAYS and never started from the page.
 * Deliberately not "added since the last update": the installation records no
 * update moment it could compare with, and an approximation of one would mark
 * every task new on an install that had just been restored from a backup.
 * A month covers the usual gap between two releases, and the mark goes away
 * on its own the moment the task has been run.
 */
final readonly class BackfillCatalogue
{
    private const int NEW_DAYS = 30;

    public function __construct(
        private BackfillTasks         $tasks,
        private BackfillRunRepository $runs,
    ) {}

    /** @return list<BackfillEntry> newest first */
    public function entries(DateTimeImmutable $now): array
    {
        $latest  = $this->runs->latestPerTask();
        $cutoff  = $now->modify(sprintf('-%d days', self::NEW_DAYS));
        $entries = [];

        foreach ($this->tasks->all() as $name => $task) {
            $run = $latest[$name] ?? null;

            $entries[] = new BackfillEntry(
                name:           $name,
                addedOn:        $task->getAddedOn(),
                windowed:       $task instanceof WindowedBackfillTaskInterface,
                countable:      $task instanceof CountableBackfillTaskInterface,
                safeToRunAgain: $task->isSafeToRunAgain(),
                isNew:          null === $run && $task->getAddedOn() >= $cutoff,
                latestRun:      $run,
            );
        }

        return $entries;
    }
}
