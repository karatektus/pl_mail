<?php

declare(strict_types=1);

namespace App\Repository\System\AdvisoryLock;

use Doctrine\DBAL\Connection;

/**
 * Postgres advisory locks.
 *
 * A lock belongs to the database session that took it, so it is only worth
 * anything taken, held and released on one connection. This repository is
 * built on the application's Connection service, which the container shares:
 * the connection a caller migrates or writes through is the one the lock is
 * held on.
 */
final readonly class AdvisoryLockRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Take a lock if nobody holds it, without waiting.
     *
     * Cast to int in SQL: pg_try_advisory_lock returns a boolean, and what a
     * driver hands back for one is its own business — true, 't' and '1' are
     * all plausible. An integer is not.
     */
    public function tryLock(int $key): bool
    {
        return 1 === (int) $this->connection->fetchOne('SELECT pg_try_advisory_lock(?)::int', [$key]);
    }

    public function unlock(int $key): void
    {
        $this->connection->executeQuery('SELECT pg_advisory_unlock(?)', [$key]);
    }

    /** Wait for a two-part lock: a namespace of the caller's and a key within it. */
    public function lockIn(int $namespace, int $key): void
    {
        $this->connection->executeQuery('SELECT pg_advisory_lock(?, ?)', [$namespace, $key]);
    }

    public function unlockIn(int $namespace, int $key): void
    {
        $this->connection->executeQuery('SELECT pg_advisory_unlock(?, ?)', [$namespace, $key]);
    }
}
