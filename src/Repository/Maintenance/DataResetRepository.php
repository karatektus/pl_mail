<?php

declare(strict_types=1);

namespace App\Repository\Maintenance;

use Doctrine\DBAL\Connection;

/**
 * Every statement a data reset issues.
 *
 * Not a ServiceEntityRepository, because none of this is about one entity:
 * TRUNCATE takes an identifier rather than a mapped class, and
 * `session_replication_role` is a session setting with no row behind it at all.
 * They are still queries, and the house rule is that queries live in a
 * repository, so they live here rather than inline in the service that
 * sequences them.
 *
 * The two sync-cursor updates sit here beside the truncations rather than on
 * AccountRepository and MailboxRepository, and that is the same decision rather
 * than a lapse: `session_replication_role` is scoped to the SESSION, so every
 * statement between disableForeignKeyChecks() and enableForeignKeyChecks() has
 * to run on this one connection. Spreading them over three classes would hide
 * that from anyone reading either end of the sequence, and the cursors are only
 * ever cleared as part of it.
 */
final readonly class DataResetRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * The tables the schema actually has right now.
     *
     * Reset lists are matched against this rather than trusted. A table dropped
     * by a later migration would otherwise abort the whole reset mid-way — with
     * foreign-key checks still disabled for the session.
     *
     * @return list<string>
     */
    public function existingTables(): array
    {
        return array_values($this->connection->createSchemaManager()->listTableNames());
    }

    /**
     * Every table the schema has, minus Doctrine's migration ledger.
     *
     * A list to keep in step would go stale: "everything" is the point of a
     * full reset, and a table added later must not survive one just because
     * nobody remembered to add it here. The ledger is excluded because it
     * records which migrations have run, which is the schema's own history and
     * not data — truncating it would make the next `doctrine:migrations:migrate`
     * try to build a schema that already exists.
     *
     * @return list<string>
     */
    public function everyDataTable(): array
    {
        return array_values(array_filter(
            $this->existingTables(),
            static fn (string $table): bool => 'doctrine_migration_versions' !== $table,
        ));
    }

    /**
     * Truncating in dependency order would mean maintaining a graph that the
     * database already knows; Postgres lets the session opt out of enforcing it
     * instead. Paired with enableForeignKeyChecks(), always.
     */
    public function disableForeignKeyChecks(): void
    {
        $this->connection->executeStatement('SET session_replication_role = replica');
    }

    public function enableForeignKeyChecks(): void
    {
        $this->connection->executeStatement('SET session_replication_role = DEFAULT');
    }

    /**
     * $table is interpolated because TRUNCATE takes an identifier, and an
     * identifier cannot be bound as a parameter. Safe because the only values
     * that ever reach here come from existingTables() or everyDataTable() —
     * Postgres' own catalogue — never from a request. DataResetter is the one
     * caller and picks from a hard-coded list intersected with that catalogue.
     *
     * CASCADE, not RESTRICT: with replication role already suppressing the
     * checks it is belt and braces, but it also empties anything a later
     * migration hangs off these tables without this class knowing about it.
     */
    public function truncate(string $table): void
    {
        $this->connection->executeStatement(sprintf('TRUNCATE TABLE %s CASCADE', $table));
    }

    /**
     * Forget where each account's provider-side sync got to, so the next run
     * re-fetches from scratch instead of asking for changes since a point whose
     * messages have just been deleted.
     */
    public function clearAccountSyncCursors(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE account SET
                gmail_history_id = NULL,
                graph_delta_links = '{}',
                last_synced_at = NULL
            SQL);
    }

    /**
     * The same for IMAP, which keeps its cursors on the mailbox rather than the
     * account. Kept mailboxes still carry them; without clearing them nothing
     * would be re-fetched.
     */
    public function clearMailboxSyncCursors(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE mailbox SET
                uid_validity = NULL,
                last_seen_uid = NULL,
                synced_at = NULL
            SQL);
    }
}
