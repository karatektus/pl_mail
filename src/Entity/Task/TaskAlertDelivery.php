<?php

declare(strict_types=1);

namespace App\Entity\Task;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Task\TaskAlertDeliveryRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * The record that one reminder of one task has gone off, for the same reason as
 * CalendarAlertDelivery: a unique row is a guarantee where a comment is not.
 *
 * Keyed by the moment rather than by the task alone, because a repeating task keeps
 * its row and moves its reminder: next week's is a different reminder and must fire.
 * Written by a raw INSERT, see TaskAlertDeliveryRepository::claim().
 */
#[ORM\Entity(repositoryClass: TaskAlertDeliveryRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'task_alert_delivery')]
// The guarantee itself. Task leads because the claim and the due query both ask
// about one task's reminder.
#[ORM\UniqueConstraint(name: 'uniq_task_alert_delivery_task_reminder', columns: ['task_id', 'reminder_at'])]
// What the prune reads: the one query not scoped to a task.
#[ORM\Index(name: 'idx_task_alert_delivery_reminder', columns: ['reminder_at'])]
#[ORM\Index(name: 'idx_task_alert_delivery_usr', columns: ['usr_id'])]
class TaskAlertDelivery
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Task $task;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    #[ORM\Column]
    public DateTimeImmutable $reminderAt;
}
