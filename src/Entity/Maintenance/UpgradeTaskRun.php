<?php

declare(strict_types=1);

namespace App\Entity\Maintenance;

use App\Repository\Maintenance\UpgradeTaskRunRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * The ledger of one-time upgrade tasks: which have run here, and how they went.
 *
 * Deliberately the same shape as Doctrine's own `migration_versions` — a row
 * per named unit of work, written by whatever applied it — because that is a
 * ledger every operator of this application already understands, and because
 * "which ones have run" is a question that must be answerable with one SELECT
 * from psql when something has gone wrong enough that the application will not
 * start.
 *
 * ATTEMPTS ARE COUNTED, and that is the difference from the migration ledger.
 * A migration that half-applies is a stopped update somebody has to look at. A
 * task here is a repair running behind a working installation, and the failure
 * worth designing for is the boring one: the container was restarted while it
 * was walking. So the row is written BEFORE the work, and a row without a
 * completion is a task to try again — up to a limit, because a task that
 * crashes the worker every time it runs would otherwise crash it forever.
 */
#[ORM\Entity(repositoryClass: UpgradeTaskRunRepository::class)]
#[ORM\Table(name: 'upgrade_task_run')]
// The claim has to be atomic against a second worker reaching the same task in
// the same instant. Every check in PHP happens before its own insert and
// therefore before the other one's; only the index can actually hold this.
#[ORM\UniqueConstraint(name: 'uniq_upgrade_task_run_name', columns: ['name'])]
class UpgradeTaskRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 128)]
    public private(set) string $name;

    /** When the most recent attempt began. Null only before the first claim. */
    #[ORM\Column(nullable: true)]
    public private(set) ?DateTimeImmutable $startedAt = null;

    /** Null until it has finished cleanly. The only thing that means "done". */
    #[ORM\Column(nullable: true)]
    public private(set) ?DateTimeImmutable $completedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    public private(set) int $attempts = 0;

    /**
     * Why the last attempt did not finish, truncated the way every other
     * stored failure in this application is: a stack trace is not a message,
     * and this one is read in a table.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    public private(set) ?string $lastError = null;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Claim the task. Called and flushed BEFORE the work starts, so a process
     * that dies mid-task leaves evidence rather than nothing.
     */
    public function recordAttempt(): void
    {
        $this->startedAt = new DateTimeImmutable();
        ++$this->attempts;
    }

    public function recordSuccess(): void
    {
        $this->completedAt = new DateTimeImmutable();
        $this->lastError   = null;
    }

    public function recordFailure(string $reason): void
    {
        $this->lastError = mb_substr($reason, 0, 500);
    }

    public function isComplete(): bool
    {
        return null !== $this->completedAt;
    }
}
