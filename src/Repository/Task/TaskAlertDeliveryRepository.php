<?php

declare(strict_types=1);

namespace App\Repository\Task;

use App\Entity\Task\Task;
use App\Entity\Task\TaskAlertDelivery;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskAlertDelivery>
 */
class TaskAlertDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskAlertDelivery::class);
    }

    /**
     * Take ownership of one reminder, or discover that somebody already has.
     *
     * Raw for CalendarAlertDeliveryRepository::claim()'s reason, which is the
     * whole design: ON CONFLICT DO NOTHING is the only form in which "did I send
     * this?" and "may I send this?" are one question. Never rolled back on a
     * failed delivery, since a reminder arriving every minute for an hour is worse
     * than one that did not arrive.
     */
    public function claim(Task $task, DateTimeImmutable $reminderAt, ?DateTimeImmutable $now = null): bool
    {
        $now = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');

        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO task_alert_delivery (task_id, usr_id, reminder_at, created_at, updated_at)
                VALUES (:task, :usr, :reminderAt, :now, :now)
                ON CONFLICT (task_id, reminder_at) DO NOTHING
                SQL,
            [
                'task'       => $task->id,
                'usr'        => $task->usr->id,
                'reminderAt' => $reminderAt->format('Y-m-d H:i:s'),
                'now'        => $now,
            ],
        );
    }

    public function pruneBefore(DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM task_alert_delivery WHERE reminder_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );
    }
}
