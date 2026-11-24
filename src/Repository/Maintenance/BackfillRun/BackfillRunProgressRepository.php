<?php

declare(strict_types=1);

namespace App\Repository\Maintenance\BackfillRun;

use App\Domain\Enum\Job\JobState;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Writing a backfill run's state and output while the task is running.
 *
 * Raw DBAL and apart from BackfillRunRepository, for the reason
 * LogEntryAppendRepository gives: the task in the same process clears the
 * entity manager between batches, and may leave it holding half a batch or
 * closed by the exception that ended it. A flush from here would write the
 * task's state along with the run's.
 */
final readonly class BackfillRunProgressRepository
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * Claim a queued run for the worker that is about to execute it.
     *
     * One UPDATE guarded by the state, so the claim and the check are the same
     * statement: a message delivered twice cannot start the task twice.
     *
     * @return bool false when the run was not queued any more
     */
    public function claim(int $runId, DateTimeImmutable $now): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            'UPDATE backfill_run SET state = :running, started_at = :now WHERE id = :id AND state = :queued',
            [
                'running' => JobState::Running->value,
                'queued'  => JobState::Queued->value,
                'now'     => $now,
                'id'      => $runId,
            ],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function recordOutput(int $runId, string $output, DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'UPDATE backfill_run SET output = :output, last_output_at = :now WHERE id = :id',
            ['output' => $output, 'now' => $now, 'id' => $runId],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function finish(int $runId, JobState $state, ?int $exitCode, ?string $reason, DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'UPDATE backfill_run SET state = :state, exit_code = :exit, failure_reason = :reason, finished_at = :now WHERE id = :id',
            [
                'state'  => $state->value,
                'exit'   => $exitCode,
                'reason' => null === $reason ? null : mb_substr($reason, 0, 500),
                'now'    => $now,
                'id'     => $runId,
            ],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }
}
