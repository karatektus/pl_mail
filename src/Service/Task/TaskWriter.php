<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Domain\DTO\Task\TaskDraft;
use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Enum\Task\TaskStatus;
use App\Domain\Exception\TaskListReadOnlyException;
use App\Entity\Task\Task;
use App\Entity\Task\TaskAttachment;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use App\Repository\Task\TaskRepository;
use App\Service\Calendar\CalendarTimeResolver;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Every local change to a task goes through here.
 *
 * One class for the reason CalendarEventWriter is one: a sync needs to know that
 * a row owes the remote a write, and a write that forgot to say so is a change
 * that never leaves. Marking lives here so no caller can forget it.
 *
 * Does not flush; it joins the caller's unit of work.
 */
final readonly class TaskWriter
{
    public function __construct(
        private EntityManagerInterface $em,
        private CalendarTimeResolver   $time,
        private TaskRecurrence         $recurrence,
        private TaskAttachmentStorage  $files,
        private TaskRepository         $tasks,
    ) {}

    /** @throws TaskListReadOnlyException */
    public function create(User $user, TaskList $list, TaskDraft $draft): Task
    {
        $this->assertWritable($list);

        $task            = new Task();
        $task->usr       = $user;
        $task->taskList  = $list;
        $task->uid       = Uuid::v7()->toRfc4122();
        $task->syncState = true === $list->isSynced() ? SyncState::PendingCreate : SyncState::Clean;

        $this->apply($task, $draft, $this->time->zoneFor($user));
        $this->em->persist($task);

        return $task;
    }

    /** @throws TaskListReadOnlyException */
    public function update(Task $task, TaskDraft $draft): void
    {
        $this->assertWritable($task->taskList);

        $this->apply($task, $draft, $this->time->zoneFor($task->usr));
        $this->markChanged($task);
    }

    /**
     * A step of $parent, or of $parent's own parent: one level is all there is.
     *
     * @throws TaskListReadOnlyException
     */
    public function addStep(Task $parent, string $title): Task
    {
        $top = $parent->parent ?? $parent;

        $this->assertWritable($top->taskList);

        $step           = new Task();
        $step->usr      = $top->usr;
        $step->taskList = $top->taskList;
        $step->parent   = $top;
        $step->uid      = Uuid::v7()->toRfc4122();
        $step->title    = mb_substr(trim($title), 0, Task::TITLE_MAX_LENGTH);
        $step->position = count($top->liveSteps());
        $step->syncState = true === $top->taskList->isSynced() ? SyncState::PendingCreate : SyncState::Clean;

        $top->steps->add($step);
        $this->em->persist($step);

        return $step;
    }

    /**
     * A new title and nothing else, which is all a step has to change. An empty one is
     * no title, and leaves the one there is.
     *
     * @throws TaskListReadOnlyException
     */
    public function rename(Task $task, string $title): void
    {
        $this->assertWritable($task->taskList);

        $title = mb_substr(trim($title), 0, Task::TITLE_MAX_LENGTH);

        if ('' === $title || $title === $task->title) {
            return;
        }

        $task->title = $title;

        $this->markChanged($task);
    }

    /**
     * Finishes the task, or, if it repeats and the rule has more to give, moves it
     * to its next date, opens its steps again and leaves a finished entry for the
     * occurrence just done. See TaskRecurrence, and Task::$occurrenceOf.
     *
     * @throws TaskListReadOnlyException
     */
    public function complete(Task $task, ?DateTimeImmutable $now = null): void
    {
        $this->assertWritable($task->taskList);

        $now = $now ?? new DateTimeImmutable();

        // Taken before the move: the entry is about the date being left behind.
        $entry = $this->occurrenceEntry($task, $now);

        if (false === $task->isStep()
            && true === $this->recurrence->advance($task, $now, $this->time->zoneFor($task->usr))) {
            $this->em->persist($entry);

            foreach ($task->liveSteps() as $step) {
                $this->reopen($step);
            }

            $this->markChanged($task);

            return;
        }

        $task->status      = TaskStatus::Completed;
        $task->completedAt = $now;

        $this->markChanged($task);
    }

    /** @throws TaskListReadOnlyException */
    public function reopen(Task $task): void
    {
        $this->assertWritable($task->taskList);

        if (true === $task->isOccurrence()) {
            $this->undoOccurrence($task);

            return;
        }

        $task->status      = TaskStatus::NeedsAction;
        $task->completedAt = null;

        $this->markChanged($task);
    }

    /**
     * Keeps an uploaded file on a task. Its link goes out with the next push, so
     * this is a change the remote is owed.
     *
     * @throws TaskListReadOnlyException
     */
    public function attach(Task $task, UploadedFile $file): TaskAttachment
    {
        $this->assertWritable($task->taskList);

        $attachment              = new TaskAttachment();
        $attachment->task        = $task;
        $attachment->usr         = $task->usr;
        $attachment->filename    = mb_substr($file->getClientOriginalName(), 0, 255);
        $attachment->contentType = mb_substr((string) $file->getClientMimeType(), 0, 255);
        $attachment->size        = (int) $file->getSize();
        $attachment->storedName  = $this->files->store($file);

        $task->attachments->add($attachment);
        $this->markChanged($task);

        return $attachment;
    }

    /** @throws TaskListReadOnlyException */
    public function detach(TaskAttachment $attachment): void
    {
        $task = $attachment->task;

        $this->assertWritable($task->taskList);

        if (null !== $attachment->storedName) {
            $this->files->remove($attachment->storedName);
        }

        $task->attachments->removeElement($attachment);
        $this->markChanged($task);
    }

    /**
     * A task the remote holds a copy of stays as a row until the remote has been
     * told, because the row is the only record that it still does. Everything
     * that reads tasks skips the state, so it leaves the screen now either way.
     *
     * @throws TaskListReadOnlyException
     */
    public function delete(Task $task): void
    {
        $this->assertWritable($task->taskList);

        // Its steps first: on a server they are resources of their own, and the
        // foreign key would take them from here without the server hearing of it.
        foreach ($task->liveSteps() as $step) {
            $this->delete($step);
        }

        if (null !== $task->remoteId) {
            $task->syncState = SyncState::PendingDelete;

            return;
        }

        foreach ($task->attachments as $attachment) {
            if (null !== $attachment->storedName) {
                $this->files->remove($attachment->storedName);
            }
        }

        $task->parent?->steps->removeElement($task);
        $this->em->remove($task);
    }

    /**
     * The list a write would land on, checked in one place so the web form, a
     * forged post and any later caller are all refused the same way. Also what
     * "respect read-only collections" comes down to on this side.
     *
     * @throws TaskListReadOnlyException
     */
    private function assertWritable(TaskList $list): void
    {
        if (true === $list->isReadOnly) {
            throw new TaskListReadOnlyException();
        }
    }

    private function occurrenceEntry(Task $task, DateTimeImmutable $now): Task
    {
        $entry                 = new Task();
        $entry->usr            = $task->usr;
        $entry->taskList       = $task->taskList;
        $entry->occurrenceOf   = $task;
        $entry->uid            = Uuid::v7()->toRfc4122();
        $entry->title          = $task->title;
        $entry->notes          = $task->notes;
        $entry->status         = TaskStatus::Completed;
        $entry->completedAt    = $now;
        $entry->priority       = $task->priority;
        $entry->recurrenceRule = $task->recurrenceRule;

        $this->copyDates($task, $entry);

        return $entry;
    }

    /**
     * Unticking an entry takes the tick back. Only the newest can put its task back
     * on that date: an older one would rewind past a tick that still stands, so it
     * only goes itself.
     */
    private function undoOccurrence(Task $entry): void
    {
        $task = $entry->occurrenceOf;

        if (null !== $task && true === $task->status->isOpen() && $this->tasks->findLatestOccurrence($task) === $entry) {
            $this->copyDates($entry, $task);

            // Back as it was, COUNT included: the move had spent it.
            $task->recurrenceRule = $entry->recurrenceRule;

            $this->markChanged($task);
        }

        $this->em->remove($entry);
    }

    private function copyDates(Task $from, Task $to): void
    {
        $to->dueDate    = $from->dueDate;
        $to->dueAt      = $from->dueAt;
        $to->startDate  = $from->startDate;
        $to->startAt    = $from->startAt;
        $to->timeZone   = $from->timeZone;
        $to->reminderAt = $from->reminderAt;
    }

    private function markChanged(Task $task): void
    {
        // An entry is plMail's own: see Task::$occurrenceOf. Marked, it would go out
        // as a create and be the copy on the server the design rules out.
        if (true === $task->isOccurrence()) {
            return;
        }

        // A create that has not gone out yet is still a create: nothing at the
        // remote to update, and calling it an update would address a null id.
        if (SyncState::PendingCreate === $task->syncState || false === $task->taskList->isSynced()) {
            return;
        }

        $task->syncState = SyncState::PendingUpdate;
    }

    private function apply(Task $task, TaskDraft $draft, DateTimeZone $zone): void
    {
        $notes = trim((string) $draft->notes);

        $task->title = mb_substr(trim($draft->title), 0, Task::TITLE_MAX_LENGTH);
        $task->notes = '' === $notes ? null : $notes;

        $this->applyDue($task, $draft, $zone);
        $this->applyRecurrence($task, $draft);
        $this->applyReminder($task, $draft, $zone);
    }

    /**
     * The four the editor offers, and `keep` for a rule from another client that
     * none of them can say: saving the dialog must not flatten FREQ=WEEKLY;BYDAY=MO,WE
     * into a plain weekly, or into nothing.
     */
    private function applyRecurrence(Task $task, TaskDraft $draft): void
    {
        if (null === $draft->recurrence || Task::RECURRENCE_KEEP === $draft->recurrence) {
            return;
        }

        $task->recurrenceRule = Task::RECURRENCE_RULES[$draft->recurrence] ?? null;
    }

    /** Read in the user's zone, stored as an instant. Empty clears it; unreadable leaves it. */
    private function applyReminder(Task $task, TaskDraft $draft, DateTimeZone $zone): void
    {
        if (null === $draft->reminder) {
            return;
        }

        if ('' === trim($draft->reminder)) {
            $task->reminderAt = null;

            return;
        }

        $local = $this->time->parseDateTime(trim($draft->reminder), $zone);

        if (null !== $local) {
            $task->reminderAt = $local->setTimezone(new DateTimeZone('UTC'));
        }
    }

    /**
     * Exactly one of the two due columns, or neither. A time without a date means
     * nothing, and a date that does not parse is treated as no date rather than
     * as today, since a wrong date is harder to notice than a missing one.
     */
    private function applyDue(Task $task, TaskDraft $draft, DateTimeZone $zone): void
    {
        $task->dueDate = null;
        $task->dueAt   = null;

        if ('' === trim($draft->dueDate)) {
            return;
        }

        if ('' !== trim($draft->dueTime)) {
            $local = $this->time->parseDateTime(sprintf('%sT%s', trim($draft->dueDate), trim($draft->dueTime)), $zone);

            if (null !== $local) {
                $task->dueAt    = $local->setTimezone(new DateTimeZone('UTC'));
                $task->timeZone = $zone->getName();

                return;
            }
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($draft->dueDate), new DateTimeZone('UTC'));

        if (false !== $date && $date->format('Y-m-d') === trim($draft->dueDate)) {
            $task->dueDate = $date;
        }
    }
}
