<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Task\RemoteTaskList;
use App\Domain\DTO\Task\RemoteTaskWriteResult;
use App\Domain\DTO\Task\TaskChangeSet;
use App\Domain\Exception\CalendarSyncException;
use App\Entity\Task\Task;
use App\Entity\Task\TaskList;

/**
 * Talks to one kind of remote task list.
 *
 * CalendarSyncDriverInterface's shape, and its contracts apply unchanged: every id
 * and token is opaque, every failure is a CalendarSyncException or a subclass (the
 * same hierarchy, because the same clients throw it and the engine's question is
 * the same — permanent, throttled, or read it again), nothing here touches
 * Doctrine, and a driver is never asked to push to a read-only list.
 *
 * Takes a CalendarSource because that is what a source is: an account or a
 * connection. Nothing about it is about calendars but the name.
 *
 * The one thing that differs is what stays with the driver. **$remotePayload is
 * this driver's.** Each task keeps the last copy the remote sent, and only the
 * driver that wrote it reads it, so a property plMail has no column for survives
 * an edit: CalDAV overwrites supported properties in the stored ICS, Graph sends
 * only the fields that changed.
 */
interface TaskSyncDriverInterface
{
    /** Must not perform I/O. Called once per driver on every sync of every list. */
    public function supports(CalendarSource $source): bool;

    /**
     * Every task list the source can see, whether or not plMail mirrors it.
     *
     * @return list<RemoteTaskList>
     *
     * @throws CalendarSyncException
     */
    public function discover(CalendarSource $source): array;

    /**
     * What changed at the remote since $syncToken.
     *
     * A null token means everything: the engine treats that listing as
     * authoritative and removes rows it did not mention, so it must not filter.
     * A dead token is CalendarChangeSet's answer, TaskChangeSet::resyncRequired().
     *
     * @throws CalendarSyncException
     */
    public function pull(TaskList $list, ?string $syncToken): TaskChangeSet;

    /**
     * Create or update one task; $task->remoteId says which.
     *
     * @throws CalendarSyncException
     */
    public function push(TaskList $list, Task $task): RemoteTaskWriteResult;

    /**
     * Remove one task. Idempotent: an already-gone task is a success.
     *
     * @throws CalendarSyncException
     */
    public function delete(TaskList $list, Task $task): void;
}
