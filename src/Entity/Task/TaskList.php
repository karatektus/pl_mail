<?php

declare(strict_types=1);

namespace App\Entity\Task;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\Calendar\Calendar;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Task\TaskListRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One list of tasks, owned by a user, and optionally mirroring a remote one.
 *
 * A table of its own rather than a role on Calendar. A calendar and a task list
 * on one CalDAV collection keep separate sync tokens, which doubles the requests
 * and is what keeps a task's failure from ever putting the calendar's sync at
 * risk (#75). Sharing the row would have shared the token.
 *
 * Its source is read the way Calendar's is: `account` for a Microsoft list on
 * that mail account's grant, `integration` for a CalDAV connection, neither for
 * a list that lives in plMail alone.
 */
#[ORM\Entity(repositoryClass: TaskListRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'task_list')]
#[ORM\Index(name: 'idx_task_list_usr', columns: ['usr_id'])]
#[ORM\Index(name: 'idx_task_list_account', columns: ['account_id'])]
#[ORM\Index(name: 'idx_task_list_integration', columns: ['integration_id'])]
// One local list per remote list, per source, as on Calendar and for its reason:
// two mirrors of one list pull every task twice and offer it as two destinations.
// On the id's hash because the provider picks its length and a btree refuses a
// row over 2704 bytes; NULLs are distinct, which leaves local lists unconstrained.
#[ORM\UniqueConstraint(name: 'uniq_task_list_account_remote', columns: ['account_id', 'remote_id_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_task_list_integration_remote', columns: ['integration_id', 'remote_id_hash'])]
// One default per user, and the guard for two first requests racing to provision
// it — TaskListProvisioner's check before inserting is a read on stale data.
// Partial, so it says nothing about the other lists.
#[ORM\UniqueConstraint(name: 'uniq_task_list_default_usr', columns: ['usr_id'], options: ['where' => '(is_default = true)'])]
class TaskList
{
    use TimestampableTrait;

    /** Calendar's palette, so a list can be any colour a calendar can. */
    public const string DEFAULT_COLOR = Calendar::DEFAULT_COLOR;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    /** The mail account whose grant reaches this list: Microsoft To Do. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?Account $account = null;

    /** The connection this list mirrors: a CalDAV collection. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?Integration $integration = null;

    #[ORM\Column(length: 120)]
    public string $name = '';

    /** #rrggbb, one of Calendar::COLORS. */
    #[ORM\Column(length: 7, options: ['default' => self::DEFAULT_COLOR])]
    public string $color = self::DEFAULT_COLOR;

    /** The one a task lands in when nothing else picked. Always a local list. */
    #[ORM\Column(options: ['default' => false])]
    public bool $isDefault = false;

    /** A mirror of somewhere that does not accept writes back. */
    #[ORM\Column(options: ['default' => false])]
    public bool $isReadOnly = false;

    #[ORM\Column(options: ['default' => 0])]
    public int $sortOrder = 0;

    /** Opaque id at the remote; only its driver reads it. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $remoteId = null;

    /** What the unique constraints compare. Generated, and nothing in PHP reads it. */
    #[ORM\Column(
        type: Types::TEXT,
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: 'text GENERATED ALWAYS AS (md5(remote_id)) STORED',
    )]
    public private(set) ?string $remoteIdHash = null;

    /** Sync-token or delta link; opaque, never parsed. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $syncToken = null;

    /** When a sync last finished without throwing. */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $lastSyncedAt = null;

    /** Why the last sync failed, phrased for a person; null when it worked. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $lastSyncError = null;

    /** Consecutive failures; only read to work out how long to wait. */
    #[ORM\Column(options: ['default' => 0])]
    public int $syncFailureCount = 0;

    /** Do not try this list again before this moment. */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $syncBackoffUntil = null;

    /**
     * Whether the failure just recorded was news. Not a column, for the reason
     * Calendar::$syncFailureWasNews is not: it is true of one attempt.
     */
    public bool $syncFailureWasNews = true;

    /** @var Collection<int, Task> */
    #[ORM\OneToMany(targetEntity: Task::class, mappedBy: 'taskList')]
    public private(set) Collection $tasks;

    public function __construct()
    {
        $this->tasks = new ArrayCollection();
    }

    /** Whether a remote stands behind this list. */
    public function isSynced(): bool
    {
        return null !== $this->remoteId;
    }

    /**
     * Whether the user may rename or delete the list itself.
     *
     * A synced list's name and existence are the remote's, and the default is
     * what a task with nowhere else to go lands in.
     */
    public function isManagedLocally(): bool
    {
        return false === $this->isSynced();
    }

    public function recordSyncSuccess(): void
    {
        $this->lastSyncedAt     = new DateTimeImmutable();
        $this->lastSyncError    = null;
        $this->syncFailureCount = 0;
        $this->syncBackoffUntil = null;
    }

    /**
     * Calendar::recordSyncFailure()'s schedule and its reasoning: doubling from a
     * quarter of an hour, capped at a day, and only news moves the schedule, so
     * Messenger's retries of one envelope do not count as a bad fortnight.
     */
    public function recordSyncFailure(string $reason, ?DateTimeImmutable $now = null): bool
    {
        $now       = $now ?? new DateTimeImmutable();
        $truncated = mb_substr($reason, 0, 500);
        $isNews    = null === $this->syncBackoffUntil
            || $this->syncBackoffUntil <= $now
            || $this->lastSyncError !== $truncated;

        $this->lastSyncError      = $truncated;
        $this->syncFailureWasNews = $isNews;

        if (true === $isNews) {
            ++$this->syncFailureCount;

            $steps = [900, 1800, 3600, 7200, 14400, 28800, 86400];
            $wait  = $steps[min(max(0, $this->syncFailureCount - 1), count($steps) - 1)];

            $this->syncBackoffUntil = $now->modify(sprintf('+%d seconds', $wait));
        }

        return $isNews;
    }
}
