<?php

declare(strict_types=1);

namespace App\Repository\Monitoring\LogEntry;

use Doctrine\DBAL\Connection;

/**
 * Writing log_entry, for DoctrineLogHandler.
 *
 * Raw DBAL and apart from LogEntryRepository, which reads the table through
 * the ORM: a log handler cannot flush an EntityManager that may be
 * mid-transaction, or in the failed state that produced the log line in the
 * first place.
 */
final readonly class LogEntryAppendRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /** @param array<string, mixed> $row column => value */
    public function append(array $row): void
    {
        $this->connection->insert('log_entry', $row);
    }
}
