<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Domain\DTO\Task\TaskEdit;
use App\Domain\DTO\Task\TaskEditResult;
use App\Domain\Exception\TaskListReadOnlyException;
use App\Entity\Task\Task;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The task dialog's Save: the task's own fields, the move to another list, its
 * steps and a file, as one change.
 *
 * Out of TaskController, which was doing all of it inside the action (CODESTYLE
 * §5.2). Each part is TaskWriter's; what is here is which parts a dialog may ask
 * for, and the order they go in. Flushes once at the end, so a list that turns
 * out to be read-only part-way leaves nothing half saved.
 */
final readonly class TaskEditorSave
{
    public function __construct(
        private TaskWriter             $writer,
        private EntityManagerInterface $em,
    ) {}

    /**
     * @param Task|null     $task   null for a new one, in $list
     * @param TaskList|null $target another list of the user's to move $task to
     *
     * @throws TaskListReadOnlyException
     */
    public function save(User $user, ?Task $task, TaskList $list, ?TaskList $target, TaskEdit $edit): TaskEditResult
    {
        $movedFrom = null;

        if (null === $task) {
            $task = $this->writer->create($user, $list, $edit->draft);
        } else {
            $this->writer->update($task, $edit->draft);

            if (null !== $target && $target !== $task->taskList) {
                $movedFrom = $task->taskList;
                $this->writer->move($task, $target);
            }
        }

        // A step and a finished entry are offered no steps and no files, so a
        // post naming some is not one the dialog made.
        if (false === $task->isStep() && false === $task->isOccurrence()) {
            $this->saveSteps($task, $edit);

            if (null !== $edit->file) {
                $this->writer->attach($task, $edit->file);
            }
        }

        $this->em->flush();

        return new TaskEditResult($task, $movedFrom);
    }

    private function saveSteps(Task $task, TaskEdit $edit): void
    {
        // By this task's own steps, so an id posted for anybody else's is never read.
        foreach ($task->liveSteps() as $step) {
            $title = $edit->stepTitles[(string) $step->id] ?? null;

            if (true === is_string($title)) {
                $this->writer->rename($step, $title);
            }
        }

        // An empty row is what the dialog always keeps one of, not a step. Every
        // row posts both fields, the empty ones too, so a tick is found by position.
        foreach ($edit->newSteps as $index => $title) {
            if (true === is_string($title) && '' !== trim($title)) {
                $step = $this->writer->addStep($task, $title);

                if ('1' === ($edit->newStepsDone[$index] ?? null)) {
                    $this->writer->complete($step);
                }
            }
        }
    }
}
