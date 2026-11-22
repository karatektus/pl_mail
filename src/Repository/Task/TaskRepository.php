<?php

declare(strict_types=1);

namespace App\Repository\Task;

use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Enum\Task\TaskStatus;
use App\Entity\Task\Task;
use App\Entity\Task\TaskAlertDelivery;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    /**
     * What one pane read returns at most.
     *
     * A cap rather than a pager: the pane is a side column, and a list that has
     * outgrown this many open tasks is not being read top to bottom. The oldest
     * due dates come first, so what falls off the end is what is furthest away.
     */
    public const int PANE_LIMIT = 500;

    /**
     * The conditions every pane read shares, so what counts as "on the pane"
     * cannot drift between the two reads below. Steps show inside their task,
     * and a row waiting for the remote to confirm its delete is gone already.
     */
    private const string PANE_BASE = <<<'SQL'
        t.usr_id = :usr
        AND t.parent_id IS NULL
        AND t.sync_state <> :pendingDelete
        SQL;

    /** Soonest first, undated last, whichever of the two columns holds the date. */
    private const string PANE_ORDER = 'COALESCE(t.due_at, t.due_date::timestamp) ASC NULLS LAST, t.id ASC';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * The open tasks of one list, or of all of them, soonest first; or its
     * finished ones, the latest first. The pane sorts the open ones into its groups.
     *
     * Raw DBAL for the ids because the order coalesces a timestamp and a date. The
     * rows are then hydrated by id so a caller gets the same entities every other
     * read hands out.
     *
     * @return list<Task>
     */
    public function findForList(User $user, ?TaskList $list, bool $finished): array
    {
        $where  = [self::PANE_BASE, 't.status IN (:statuses)'];
        $types  = ['statuses' => ArrayParameterType::STRING];
        $params = $this->baseParams($user) + [
            'statuses' => true === $finished
                ? [TaskStatus::Completed->value, TaskStatus::Cancelled->value]
                : self::openStatuses(),
        ];

        if (null !== $list) {
            $where[]        = 't.task_list_id = :list';
            $params['list'] = $list->id;
        }

        return $this->hydrate($where, $params, $types, true === $finished ? 't.completed_at DESC NULLS LAST, t.id DESC' : self::PANE_ORDER);
    }

    /**
     * What My Day draws from: open and due, starting or reminding today, open
     * and overdue, or finished today.
     *
     * The window is the reader's day as two instants, and `today` the same day
     * as a date, because the two kinds of column need the two forms — see
     * Task's class docblock. The pane sorts these into its groups.
     *
     * @return list<Task>
     */
    public function findForMyDay(
        User $user,
        DateTimeImmutable $dayStart,
        DateTimeImmutable $dayEnd,
        string $today,
    ): array {
        $where = [
            self::PANE_BASE,
            <<<'SQL'
            (
                (
                    t.status IN (:open)
                    AND (
                        t.due_date <= :today
                        OR t.due_at < :dayEnd
                        OR t.start_date = :today
                        OR (t.start_at >= :dayStart AND t.start_at < :dayEnd)
                        OR (t.reminder_at >= :dayStart AND t.reminder_at < :dayEnd)
                    )
                )
                OR (t.status IN (:done) AND t.completed_at >= :dayStart AND t.completed_at < :dayEnd)
            )
            SQL,
        ];

        $params = $this->baseParams($user) + [
            'open'     => self::openStatuses(),
            'done'     => [TaskStatus::Completed->value, TaskStatus::Cancelled->value],
            'today'    => $today,
            'dayStart' => $dayStart,
            'dayEnd'   => $dayEnd,
        ];

        $types = [
            'open'     => ArrayParameterType::STRING,
            'done'     => ArrayParameterType::STRING,
            'dayStart' => Types::DATETIME_IMMUTABLE,
            'dayEnd'   => Types::DATETIME_IMMUTABLE,
        ];

        return $this->hydrate($where, $params, $types, self::PANE_ORDER);
    }

    /**
     * What the pusher owes the remote. Parents before their steps, so a step's
     * RELATED-TO never names a task the server has not been told about, then the
     * deletes the other way round, so a step leaves before the task it hung from.
     *
     * @return list<Task>
     */
    public function findPendingSync(TaskList $list): array
    {
        return $this->createQueryBuilder('task')
            ->where('task.taskList = :list')
            ->andWhere('task.syncState <> :clean')
            ->setParameter('list', $list)
            ->setParameter('clean', SyncState::Clean)
            ->addSelect(
                'CASE WHEN task.syncState <> :delete AND task.parent IS NULL THEN 0 '
                . 'WHEN task.syncState <> :delete THEN 1 '
                . 'WHEN task.parent IS NOT NULL THEN 2 ELSE 3 END AS HIDDEN pushOrder',
            )
            ->addOrderBy('pushOrder', 'ASC')
            ->addOrderBy('task.id', 'ASC')
            ->setParameter('delete', SyncState::PendingDelete)
            ->getQuery()
            ->getResult();
    }

    /**
     * Open tasks whose reminder has come due and has not gone out.
     *
     * The anti-join saves the claim a statement for each row it would refuse; the
     * claim stays the guarantee. A step has no reminder of its own to the pane, but
     * one from another client is still a reminder somebody set.
     *
     * @return list<Task>
     */
    public function findDueReminders(DateTimeImmutable $from, DateTimeImmutable $to, int $limit = 500): array
    {
        return $this->createQueryBuilder('task')
            ->leftJoin(TaskAlertDelivery::class, 'delivery', 'WITH', 'delivery.task = task AND delivery.reminderAt = task.reminderAt')
            ->where('task.reminderAt > :from')
            ->andWhere('task.reminderAt <= :to')
            ->andWhere('task.status IN (:open)')
            ->andWhere('task.syncState <> :pendingDelete')
            ->andWhere('delivery.id IS NULL')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('open', self::openStatuses())
            ->setParameter('pendingDelete', SyncState::PendingDelete)
            ->orderBy('task.reminderAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** The newest finished entry of a repeating task; see TaskWriter::reopen(). */
    public function findLatestOccurrence(Task $task): ?Task
    {
        return $this->findOneBy(['occurrenceOf' => $task], ['completedAt' => 'DESC', 'id' => 'DESC']);
    }

    public function findOneByRemoteId(TaskList $list, string $remoteId): ?Task
    {
        return $this->findOneBy(['taskList' => $list, 'remoteId' => $remoteId]);
    }

    public function findOneByUid(TaskList $list, string $uid): ?Task
    {
        return '' === $uid ? null : $this->findOneBy(['taskList' => $list, 'uid' => $uid]);
    }

    /** @return list<Task> what a full read can find to be gone */
    public function findWithRemoteId(TaskList $list): array
    {
        return $this->createQueryBuilder('task')
            ->where('task.taskList = :list')
            ->andWhere('task.remoteId IS NOT NULL')
            ->setParameter('list', $list)
            ->getQuery()
            ->getResult();
    }

    /** @return array{usr: int|null, pendingDelete: string} */
    private function baseParams(User $user): array
    {
        return ['usr' => $user->id, 'pendingDelete' => SyncState::PendingDelete->value];
    }

    /** @return list<string> */
    private static function openStatuses(): array
    {
        return [TaskStatus::NeedsAction->value, TaskStatus::InProcess->value];
    }

    /**
     * @param list<string>         $where
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     *
     * @return list<Task>
     */
    private function hydrate(array $where, array $params, array $types, string $order): array
    {
        $sql = sprintf(
            'SELECT t.id FROM task t WHERE %s ORDER BY %s LIMIT %d',
            implode(' AND ', $where),
            $order,
            self::PANE_LIMIT,
        );

        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn($sql, $params, $types);

        if ([] === $ids) {
            return [];
        }

        // findBy has no idea about the order above, so it is put back by id.
        $byId = [];

        foreach ($this->findBy(['id' => $ids]) as $task) {
            $byId[$task->id] = $task;
        }

        $tasks = [];

        foreach ($ids as $id) {
            if (true === isset($byId[(int) $id])) {
                $tasks[] = $byId[(int) $id];
            }
        }

        return $tasks;
    }
}
