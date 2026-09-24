<?php

declare(strict_types=1);

namespace App\Infrastructure\Setup;

use App\Repository\Monitoring\PostgresStatusRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;

/**
 * Creates the tables the database-backed cache pools keep their items in,
 * before anything reads from them.
 *
 * Symfony's DoctrineDbalAdapter creates its table on the first WRITE, and a
 * read before that fails and is logged as a warning. On these pools the reads
 * come first, and constantly: every Messenger consumer checks the
 * restart-workers signal on every loop (StopWorkerOnRestartSignalListener),
 * and nothing writes that signal until an administrator presses "Restart
 * workers". A fresh install logged a warning a second from each consumer until
 * somebody did.
 *
 * The migrations leave the table out on purpose, and this keeps it that way:
 * the adapter still owns the DDL and is only asked to run it early. A
 * hand-written CREATE TABLE would be a second copy of Symfony's that nothing
 * keeps in step.
 */
final readonly class DatabaseCacheTables
{
    /**
     * @param iterable<DoctrineDbalAdapter> $pools every one the container defines; see DatabaseCachePoolsPass
     */
    public function __construct(
        private iterable $pools,
        private Connection $connection,
        private PostgresStatusRepository $database,
    ) {
    }

    /**
     * @return list<string> the tables this call created; empty when they all existed already
     */
    public function createMissing(): array
    {
        $created = [];

        foreach ($this->pools as $pool) {
            // The adapter is asked for its table rather than the name being
            // assumed here, so its options stay the one place that decides it.
            // A pool on another connection answers with nothing: its table is
            // not in this database, and looking for it here would answer the
            // wrong question.
            $wanted = $pool->configureSchema(new Schema(), $this->connection, static fn (): bool => false);

            foreach ($wanted->getTables() as $table) {
                $name = $table->getObjectName()->toString();

                if ($this->database->hasTable($name)) {
                    continue;
                }

                $pool->createTable();
                $created[] = $name;
            }
        }

        return $created;
    }
}
