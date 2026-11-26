<?php

declare(strict_types=1);

namespace App\Entity\Task;

use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Enum\Task\TaskStatus;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Task\TaskRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One task, or one step of a task.
 *
 * Its own table rather than a kind of CalendarEvent. A task often has no start
 * date and needs no occurrence table, and in CalendarEvent every calendar view
 * would have to filter tasks back out (#75).
 *
 * **Due dates are two columns, and exactly one of them is set.** A date the user
 * gave without a time is a DATE, never midnight UTC: stored as an instant it
 * lands on the previous day for anyone west of Greenwich and the wrong My Day
 * for everybody else. $dueAt is an instant, for the case that does have a time.
 * The start date follows the same rule.
 *
 * **What the columns do not hold stays in $remotePayload.** The last copy the
 * remote sent, opaque to everything but the driver that wrote it. Only supported
 * fields are ever overwritten from it, so a property plMail has never heard of
 * survives an edit made here.
 */
#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'task')]
#[ORM\Index(name: 'idx_task_list_parent', columns: ['task_list_id', 'parent_id'])]
#[ORM\Index(name: 'idx_task_parent', columns: ['parent_id'])]
#[ORM\Index(name: 'idx_task_occurrence_of', columns: ['occurrence_of_id'])]
// What every pane read starts from. The user leads because each read is scoped
// to one person, and the due columns follow because that is what My Day and the
// overdue filter range over.
#[ORM\Index(name: 'idx_task_usr_due', columns: ['usr_id', 'due_date', 'due_at'])]
// What the pusher sweeps before a pull, in the order CalendarEvent's does and
// for its reason: scoped to a list, over a column with four values.
#[ORM\Index(name: 'idx_task_list_sync_state', columns: ['task_list_id', 'sync_state'])]
// The pull's identity lookup. On the hash because the remote picks the length.
#[ORM\Index(name: 'idx_task_list_remote', columns: ['task_list_id', 'remote_id_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_task_list_uid', columns: ['task_list_id', 'uid_hash'])]
class Task
{
    use TimestampableTrait;

    /** How long a title may be on any provider plMail talks to. */
    public const int TITLE_MAX_LENGTH = 255;

    /**
     * What the editor's repeat field offers, as the rule each one means.
     *
     * @var array<string, string>
     */
    public const array RECURRENCE_RULES = [
        'daily'   => 'FREQ=DAILY',
        'weekly'  => 'FREQ=WEEKLY',
        'monthly' => 'FREQ=MONTHLY',
        'yearly'  => 'FREQ=YEARLY',
    ];

    /** The editor's way of saying "leave the rule another client wrote alone". */
    public const string RECURRENCE_KEEP = 'keep';

    /** RFC 5545: 0 is undefined, 1 is highest, 9 is lowest. */
    public const int PRIORITY_UNDEFINED = 0;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TaskList::class, inversedBy: 'tasks')]
    #[ORM\JoinColumn(name: 'task_list_id', nullable: false, onDelete: 'CASCADE')]
    public TaskList $taskList;

    /**
     * Denormalised from the list, because every read here is scoped to a user
     * and the alternative is joining task_list into it for nothing.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    /** The task this is a step of. One level only; TaskWriter refuses a second. */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?Task $parent = null;

    /**
     * The repeating task this is a finished entry of: what one tick left behind.
     *
     * **plMail's own, and never a server's.** The design rules out a finished copy
     * per occurrence on CalDAV, since it would fill the list there, so an entry has
     * no remote id and TaskWriter never marks it as owed. A full read cannot remove
     * it for the same reason: it has no id to be missing by.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?Task $occurrenceOf = null;

    /** @var Collection<int, Task> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    public private(set) Collection $steps;

    /** The iCalendar UID. TEXT, because another client picks the length. */
    #[ORM\Column(type: Types::TEXT)]
    public string $uid = '';

    /** What uniq_task_list_uid compares. Generated, and nothing in PHP reads it. */
    #[ORM\Column(
        type: Types::TEXT,
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: 'text GENERATED ALWAYS AS (md5(uid)) STORED',
    )]
    public private(set) ?string $uidHash = null;

    #[ORM\Column(type: Types::TEXT)]
    public string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $notes = null;

    #[ORM\Column(length: 20, enumType: TaskStatus::class, options: ['default' => 'needs-action'])]
    public TaskStatus $status = TaskStatus::NeedsAction;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $completedAt = null;

    /** Due on a day, with no time. See the class docblock. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $dueDate = null;

    /** Due at an instant, UTC. See the class docblock. */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $dueAt = null;

    /** Starts on a day, with no time. No input in the first version; read and kept. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $startDate = null;

    /** Starts at an instant, UTC. */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $startAt = null;

    /** IANA zone the times above were written in, for writing them back the same way. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $timeZone = null;

    /** 0–9, RFC 5545's scale. No input in the first version; read and kept. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    public int $priority = self::PRIORITY_UNDEFINED;

    /** One point in time per task, UTC. */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $reminderAt = null;

    /**
     * An RRULE, as RFC 5545 spells it. Null on a task that does not repeat. One
     * string rather than JSCalendar's object: nothing queries it, and both
     * providers' forms convert to it at their own edge.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $recurrenceRule = null;

    /** Order among its parent's steps. Top-level tasks sort by their dates instead. */
    #[ORM\Column(options: ['default' => 0])]
    public int $position = 0;

    #[ORM\Column(length: 20, enumType: SyncState::class, options: ['default' => 'clean'])]
    public SyncState $syncState = SyncState::Clean;

    /** Opaque id at the remote; only its driver reads it. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $remoteId = null;

    /** What idx_task_list_remote reads. Generated, and nothing in PHP reads it. */
    #[ORM\Column(
        type: Types::TEXT,
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: 'text GENERATED ALWAYS AS (md5(remote_id)) STORED',
    )]
    public private(set) ?string $remoteIdHash = null;

    /** Etag or change key; compared for equality and nothing else. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $remoteEtag = null;

    /** The last copy the remote sent. See the class docblock. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $remotePayload = null;

    /** @var Collection<int, TaskAttachment> */
    #[ORM\OneToMany(targetEntity: TaskAttachment::class, mappedBy: 'task', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public private(set) Collection $attachments;

    public function __construct()
    {
        $this->steps       = new ArrayCollection();
        $this->attachments = new ArrayCollection();
    }

    /**
     * Its steps as the screen knows them: a step waiting for the remote to hear
     * of its delete has already gone. A method, because it filters.
     *
     * @return list<Task>
     */
    public function liveSteps(): array
    {
        return array_values($this->steps->filter(
            static fn (Task $step): bool => SyncState::PendingDelete !== $step->syncState,
        )->toArray());
    }

    /** The editor's value for its repeat field: one of the four, `keep`, or none. */
    public function recurrenceChoice(): string
    {
        if (null === $this->recurrenceRule || '' === $this->recurrenceRule) {
            return '';
        }

        return array_search($this->recurrenceRule, self::RECURRENCE_RULES, true) ?: self::RECURRENCE_KEEP;
    }

    /** Whether this is what one tick of a repeating task left behind. */
    public function isOccurrence(): bool
    {
        return null !== $this->occurrenceOf;
    }

    /** Whether this is a step of another task. */
    public function isStep(): bool
    {
        return null !== $this->parent;
    }

    /** The day it is due in $zone, whichever of the two columns says so. */
    public function dueDay(DateTimeZone $zone): ?string
    {
        if (null !== $this->dueDate) {
            return $this->dueDate->format('Y-m-d');
        }

        return $this->dueAt?->setTimezone($zone)->format('Y-m-d');
    }

    /** Whether the due date has passed. A date is only overdue the day after. */
    public function isOverdue(DateTimeImmutable $now, DateTimeZone $zone): bool
    {
        if (false === $this->status->isOpen()) {
            return false;
        }

        if (null !== $this->dueAt) {
            return $this->dueAt < $now;
        }

        return null !== $this->dueDate
            && $this->dueDate->format('Y-m-d') < $now->setTimezone($zone)->format('Y-m-d');
    }
}
