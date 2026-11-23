<?php

declare(strict_types=1);

namespace App\Service\Maintenance\Backfill;

use App\Command\Backfill\BackfillTaskInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every registered backfill, by name.
 *
 * One list for both doors, `app:backfill` and Admin → Backfills, so a task
 * that is added shows up in both or in neither.
 *
 * **Newest first**, by getAddedOn(), and by name within a day. That is the
 * order both doors show, because the newest task is the one an update most
 * likely asks for; the console used to sort by name, which put the task
 * somebody had just read about in the release notes wherever its first letter
 * happened to fall.
 */
final readonly class BackfillTasks
{
    /** @var array<string, BackfillTaskInterface> */
    private array $tasks;

    /**
     * @param iterable<BackfillTaskInterface> $tasks
     */
    public function __construct(
        #[AutowireIterator('app.backfill_task')]
        iterable $tasks,
    ) {
        $list = [...$tasks];

        usort($list, static fn (BackfillTaskInterface $a, BackfillTaskInterface $b): int =>
            [$b->getAddedOn(), $a->getName()] <=> [$a->getAddedOn(), $b->getName()]);

        $byName = [];

        foreach ($list as $task) {
            $byName[$task->getName()] = $task;
        }

        $this->tasks = $byName;
    }

    /** @return array<string, BackfillTaskInterface> newest first */
    public function all(): array
    {
        return $this->tasks;
    }

    public function find(string $name): ?BackfillTaskInterface
    {
        return $this->tasks[$name] ?? null;
    }
}
