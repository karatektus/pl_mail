<?php

declare(strict_types=1);

namespace App\Repository\Contacts\ContactChangeLog;

use Doctrine\DBAL\Connection;

/**
 * Writing contact_change_log, for the listener that records changes as Doctrine flushes.
 *
 * Raw DBAL on the connection, apart from ContactChangeLogRepository: the listener runs
 * inside the flush, where it can neither flush again nor depend on the entity
 * manager it is listening to.
 */
final readonly class ContactChangeLogAppendRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /** @param array<string, mixed> $row column => value */
    public function append(array $row): void
    {
        $this->connection->insert('contact_change_log', $row);
    }
}
