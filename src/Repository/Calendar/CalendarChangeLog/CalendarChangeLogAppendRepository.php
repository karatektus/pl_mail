<?php

declare(strict_types=1);

namespace App\Repository\Calendar\CalendarChangeLog;

use Doctrine\DBAL\Connection;

/**
 * Writing calendar_change_log, for the listener that records changes as Doctrine flushes.
 *
 * Raw DBAL on the connection, apart from CalendarChangeLogRepository: the listener runs
 * inside the flush, where it can neither flush again nor depend on the entity
 * manager it is listening to.
 */
final readonly class CalendarChangeLogAppendRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /** @param array<string, mixed> $row column => value */
    public function append(array $row): void
    {
        $this->connection->insert('calendar_change_log', $row);
    }
}
