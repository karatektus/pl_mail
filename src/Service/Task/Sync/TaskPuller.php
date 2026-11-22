<?php

declare(strict_types=1);

namespace App\Service\Task\Sync;

use App\Domain\DTO\Task\RemoteTask;
use App\Domain\DTO\Task\TaskChangeSet;
use App\Domain\Enum\Calendar\SyncState;
use App\Entity\Task\Task;
use App\Entity\Task\TaskAttachment;
use App\Entity\Task\TaskList;
use App\Repository\Task\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * What one window of remote changes means against the local rows.
 *
 * The conflict rules are CalendarSyncService's and carry over as they are: a
 * matching etag is not a change, and a real change means the remote wins, loudly.
 * Only a full read removes what it did not mention, and a task the remote has not
 * seen yet is never in the running: it has no remote id to be missed by.
 *
 * Steps are placed in a second pass, because a step can arrive before its parent
 * in the same window and a row does not exist yet to point at.
 *
 * Does not flush — it joins the caller's unit of work.
 */
final readonly class TaskPuller
{
    public function __construct(
        private TaskRepository         $tasks,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    /** @return int how many tasks this window created, changed or removed */
    public function apply(TaskList $list, TaskChangeSet $changes, bool $wasFullRead): int
    {
        $touched = 0;
        $seen    = [];

        /** @var array<string, array{Task, string|null}> $placed uid → the row and its parent's uid */
        $placed = [];

        foreach ($changes->tasks as $remote) {
            if (true === $remote->isDeleted) {
                $task = $this->tasks->findOneByRemoteId($list, $remote->remoteId);

                if (null !== $task) {
                    $this->em->remove($task);
                    ++$touched;
                }

                continue;
            }

            $seen[$remote->remoteId] = true;

            $task = $this->tasks->findOneByRemoteId($list, $remote->remoteId)
                ?? $this->tasks->findOneByUid($list, $remote->uid);

            if (null !== $task && null !== $remote->etag && $task->remoteEtag === $remote->etag) {
                continue;
            }

            $task = $this->applyOne($list, $task, $remote);
            ++$touched;

            $placed[$task->uid] = [$task, $remote->parentUid];
        }

        // Before the second pass: a new row has to be known to the lookup by uid
        // if a step in this same window names it.
        $this->em->flush();

        foreach ($placed as [$task, $parentUid]) {
            $this->placeUnderParent($list, $task, $parentUid);
        }

        if (true === $wasFullRead) {
            foreach ($this->tasks->findWithRemoteId($list) as $task) {
                if (false === array_key_exists((string) $task->remoteId, $seen)) {
                    $this->em->remove($task);
                    ++$touched;
                }
            }
        }

        // Only here, after the whole window applied: a run that fails halfway
        // reads the same window again rather than skipping what it did not reach.
        $list->syncToken = $changes->nextSyncToken;

        return $touched;
    }

    private function applyOne(TaskList $list, ?Task $task, RemoteTask $remote): Task
    {
        if (null === $task) {
            $task           = new Task();
            $task->taskList = $list;
            $task->usr      = $list->usr;
            $this->em->persist($task);
        } elseif (true === $task->syncState->isPending()) {
            // Push runs first, so this only happens when that push failed. The
            // old values go in the log: this is the last anyone will see of them.
            $this->logger->warning('TaskSync: a task changed on both sides, the remote wins', [
                'listId'    => $list->id,
                'taskId'    => $task->id,
                'syncState' => $task->syncState->value,
                'discarded' => ['title' => $task->title, 'notes' => $task->notes, 'status' => $task->status->value],
            ]);
        }

        $task->uid            = '' === $remote->uid ? $remote->remoteId : $remote->uid;
        $task->title          = mb_substr($remote->title, 0, 65535);
        $task->notes          = $remote->notes;
        $task->status         = $remote->status;
        $task->completedAt    = $remote->completedAt;
        $task->dueDate        = $remote->dueDate;
        $task->dueAt          = $remote->dueAt;
        $task->startDate      = $remote->startDate;
        $task->startAt        = $remote->startAt;
        $task->timeZone       = $remote->timeZone;
        $task->priority       = $remote->priority;
        $task->reminderAt     = $remote->reminderAt;
        $task->recurrenceRule = $remote->recurrenceRule;
        $task->position       = $remote->position;
        $task->remoteId       = $remote->remoteId;
        $task->remoteEtag     = $remote->etag;
        $task->remotePayload  = $remote->payload;
        $task->syncState      = SyncState::Clean;

        $this->replaceLinks($task, $remote);

        return $task;
    }

    /**
     * A foreign link is the remote's, so its set replaces what is here. A file kept
     * here is never in question: dropping its ATTACH does not cost anyone the file
     * (see TaskAttachment), and the next write puts the link back.
     */
    private function replaceLinks(Task $task, RemoteTask $remote): void
    {
        $wanted = [];

        foreach ($remote->links as $link) {
            $wanted[$link['url']] = $link['filename'];
        }

        foreach ($task->attachments as $attachment) {
            if (true === $attachment->isStoredHere()) {
                continue;
            }

            if (true === array_key_exists((string) $attachment->externalUrl, $wanted)) {
                unset($wanted[(string) $attachment->externalUrl]);
            } else {
                $task->attachments->removeElement($attachment);
            }
        }

        foreach ($wanted as $url => $filename) {
            $attachment              = new TaskAttachment();
            $attachment->task        = $task;
            $attachment->usr         = $task->usr;
            $attachment->filename    = $filename;
            $attachment->externalUrl = (string) $url;

            $task->attachments->add($attachment);
        }
    }

    /**
     * One level only, which is all the pane draws: a step of a step goes under
     * its top-most task, so nothing another client nested disappears from view.
     * A parent the window and the table both lack leaves it a task of its own,
     * since hiding it under nothing would lose it.
     */
    private function placeUnderParent(TaskList $list, Task $task, ?string $parentUid): void
    {
        if (null === $parentUid) {
            $task->parent = null;

            return;
        }

        $parent = $this->tasks->findOneByUid($list, $parentUid);
        $hops   = 0;

        while (null !== $parent?->parent && $hops < 16) {
            $parent = $parent->parent;
            ++$hops;
        }

        $task->parent = $parent === $task ? null : $parent;
    }
}
