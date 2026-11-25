<?php

declare(strict_types=1);

namespace App\Entity\Maintenance;

use App\Domain\Enum\Job\JobState;
use App\Entity\User\User;
use App\Repository\Maintenance\BackfillRun\BackfillRunRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One backfill started from Admin → Backfills, and what it printed.
 *
 * A row per run rather than a row per task, unlike UpgradeTaskRun: a backfill
 * is run when somebody decides to, as often as they decide to, and "what did
 * it do last Tuesday" is a question the page answers from these rows.
 *
 * NOT A BackgroundJob, although it borrows that table's states. A job is a
 * person's own bulk action, shown in their topbar indicator with a count, and
 * reaped when it stops moving for five minutes. A backfill has no count it can
 * report — each task keeps its own progress bar and writes it as text — and a
 * table repair can sit inside one long query for longer than that window
 * without being dead. What an administrator needs from it is the output, so
 * that is what the row keeps.
 *
 * Written through BackfillRunProgressRepository while the task runs, never
 * through the entity manager: the tasks clear the manager between batches,
 * and a flush from here would write whatever a task had half-built.
 *
 * Runs from the console are not recorded. That command prints to the terminal
 * it was typed in, and a ledger that held only some console runs would answer
 * "has this run here?" wrongly in both directions.
 */
#[ORM\Entity(repositoryClass: BackfillRunRepository::class)]
#[ORM\Table(name: 'backfill_run')]
// Task first: the page asks "the latest run of each task" and "is one of
// this task's runs still going", both by task, newest id last.
#[ORM\Index(name: 'idx_backfill_run_task_name_id', columns: ['task_name', 'id'])]
class BackfillRun
{
    /**
     * How much of the output is kept: the end of it.
     *
     * A walk over a large mailbox prints a progress line per tenth and a
     * summary, which is a few kilobytes; a task that writes a line per message
     * would otherwise grow this row without limit. The end is what is kept
     * because the end is where the result and any error are.
     */
    public const int OUTPUT_LIMIT = 65536;

    /**
     * Half an hour without a line. Every task prints at least its progress
     * every tenth of the way, so a run silent for this long has lost its
     * worker — restarted mid-run, or killed — rather than being busy.
     */
    public const int QUIET_SECONDS = 1800;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 64)]
    public private(set) string $taskName;

    /**
     * The window chosen on the page ('24h', '7d', '4w', 'all'), for a task
     * that re-reads mail. Null for a table repair, which takes none.
     */
    #[ORM\Column(name: 'time_window', length: 16, nullable: true)]
    public private(set) ?string $window;

    /** Who pressed Run. Kept when the run outlives the account. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_by_id', nullable: true, onDelete: 'SET NULL')]
    public private(set) ?User $requestedBy;

    #[ORM\Column(length: 16, enumType: JobState::class)]
    public private(set) JobState $state = JobState::Queued;

    #[ORM\Column]
    public private(set) DateTimeImmutable $queuedAt;

    #[ORM\Column(nullable: true)]
    public private(set) ?DateTimeImmutable $startedAt = null;

    /**
     * When the task last printed something. How the page tells a run that is
     * working from one whose worker went away: see isQuiet().
     */
    #[ORM\Column(nullable: true)]
    public private(set) ?DateTimeImmutable $lastOutputAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?DateTimeImmutable $finishedAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $exitCode = null;

    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    public private(set) string $output = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $failureReason = null;

    public function __construct(string $taskName, ?string $window, ?User $requestedBy)
    {
        $this->taskName    = $taskName;
        $this->window      = $window;
        $this->requestedBy = $requestedBy;
        $this->queuedAt    = new DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return $this->state->isActive();
    }

    /**
     * Running, and silent for longer than QUIET_SECONDS.
     *
     * The page says so instead of showing a run as going for ever. The
     * message that carried it is settled when the transport hands it out
     * again (see BackfillRunner::execute()).
     */
    public function isQuiet(DateTimeImmutable $now): bool
    {
        if (JobState::Running !== $this->state) {
            return false;
        }

        $last = $this->lastOutputAt ?? $this->startedAt ?? $this->queuedAt;

        return $now->getTimestamp() - $last->getTimestamp() > self::QUIET_SECONDS;
    }

    /** The output as the page shows it: the last lines, not the whole run. */
    public function tail(int $lines = 12): string
    {
        $all = preg_split('~\R~', rtrim($this->output)) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }
}
