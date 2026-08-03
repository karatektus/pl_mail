<?php

declare(strict_types=1);

namespace App\Repository\Monitoring;

use Doctrine\DBAL\Connection;

/**
 * Every read plMail makes against Postgres' own catalogue.
 *
 * Not a ServiceEntityRepository, and it never will be: `pg_stat_activity`,
 * `pg_stat_database`, `pg_stat_statements` and `pg_extension` are the server
 * describing itself. There is no entity to map them to and there should not be
 * one — a migration that created these tables would be creating them a second
 * time. They are still queries, and the house rule is that queries live in a
 * repository, so they live here rather than inline in the service that shapes
 * them for the dashboard. Same decision, and same reasoning, as
 * DataResetRepository.
 *
 * Nothing here throws. A monitoring read that fails is a gap in a dashboard,
 * never a 500 on the page that was going to display it, and the callers have no
 * better answer than the empty one this returns — so the degradation is decided
 * here, once, instead of at each of the call sites.
 */
final readonly class PostgresStatusRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Is the database answering at all — the healthcheck's only hard question.
     *
     * `SELECT 1` rather than a ping on the driver: a connection object can be
     * "open" while the server behind it has gone away, and a round trip is the
     * only thing that distinguishes the two.
     */
    public function isReachable(): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether pg_stat_statements is installed. Everything else here works
     * unconditionally; the statement views do not exist without it.
     */
    public function hasStatStatements(): bool
    {
        try {
            return (bool) $this->connection->fetchOne(
                "SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements')",
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether the server is even able to collect statement statistics.
     *
     * pg_stat_statements is a shared library, not just an extension: it has to
     * be named in shared_preload_libraries, which is read once at startup. So
     * CREATE EXTENSION on a server without it produces an extension whose view
     * raises on every read — worse than not having it, because the failure
     * arrives later and somewhere else.
     */
    public function canCollectStatements(): bool
    {
        try {
            $preloaded = (string) $this->connection->fetchOne('SHOW shared_preload_libraries');
        } catch (\Throwable) {
            return false;
        }

        return str_contains($preloaded, 'pg_stat_statements');
    }

    /**
     * Turn statement collection on, if the server can support it and the
     * connected role is allowed to.
     *
     * Enabled at boot rather than left to somebody noticing the panel, because
     * the numbers only describe queries run *since* the extension existed: a
     * slow page reported on Tuesday is not explained by statistics that started
     * on Wednesday. Creating it costs nothing on a server already loading the
     * library.
     *
     * @return bool true when the extension is present afterwards, whether this
     *              call created it or found it already there
     */
    public function enableStatStatements(): bool
    {
        if (true === $this->hasStatStatements()) {
            return true;
        }

        if (false === $this->canCollectStatements()) {
            return false;
        }

        try {
            // IF NOT EXISTS rather than a check-then-create: two containers
            // boot at once and both would pass the check.
            $this->connection->executeStatement('CREATE EXTENSION IF NOT EXISTS pg_stat_statements');
        } catch (\Throwable) {
            // Needs a superuser or a role granted pg_create_extension. Refusing
            // is a legitimate answer from a locked-down install, and it must not
            // stop the application starting.
            return false;
        }

        return $this->hasStatStatements();
    }

    /**
     * Statements whose *average* is slow, worst first — "which queries are
     * slow". Floored, because a statement that averages a fraction of a
     * millisecond is noise on this view however often it runs.
     *
     * @return list<array<string,mixed>>
     */
    public function statementsSlowestByMean(int $limit, float $meanFloorMs): array
    {
        return $this->fetchStatements('mean_exec_time', $limit, $meanFloorMs);
    }

    /**
     * Statements by cumulative time — "where does the database spend its time".
     * Unfloored on purpose: a query that takes 0.1ms and runs ten million times
     * is precisely what this view exists to surface, and the mean floor would
     * hide it.
     *
     * @return list<array<string,mixed>>
     */
    public function statementsHeaviestByTotal(int $limit): array
    {
        return $this->fetchStatements('total_exec_time', $limit, null);
    }

    /**
     * Backends running something right now, longest first — what to look at
     * when the database feels stuck at this moment rather than on average.
     *
     * @return list<array<string,mixed>>
     */
    public function activeBackends(int $limit = 25): array
    {
        try {
            return $this->connection->fetchAllAssociative(
                "SELECT
                    pid,
                    state,
                    wait_event_type,
                    wait_event,
                    EXTRACT(EPOCH FROM (now() - query_start))::int AS duration_seconds,
                    query
                 FROM pg_stat_activity
                 WHERE datname = current_database()
                   AND pid <> pg_backend_pid()
                   AND state IS DISTINCT FROM 'idle'
                   AND query <> ''
                 ORDER BY duration_seconds DESC NULLS LAST
                 LIMIT :limit",
                ['limit' => $limit],
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The current database's row in pg_stat_database — cache hits, transaction
     * counts, deadlocks, temp-file spill. Null when it cannot be read.
     *
     * @return array<string,mixed>|null
     */
    public function databaseStats(): ?array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT
                    numbackends,
                    xact_commit,
                    xact_rollback,
                    blks_read,
                    blks_hit,
                    deadlocks,
                    temp_files,
                    temp_bytes
                 FROM pg_stat_database
                 WHERE datname = current_database()',
            );
        } catch (\Throwable) {
            return null;
        }

        return false === $row ? null : $row;
    }

    /**
     * The largest tables, with their total on-disk size — "what is actually
     * using the disk", which no entity can answer about itself.
     *
     * Size includes indexes and TOAST (pg_total_relation_size), because the
     * question being asked is about the disk rather than about rows.
     *
     * @return list<array<string,mixed>>
     */
    public function tableSizes(int $limit): array
    {
        try {
            return $this->connection->fetchAllAssociative(
                "SELECT
                    c.relname AS table_name,
                    pg_size_pretty(pg_total_relation_size(c.oid)) AS pretty_size,
                    pg_total_relation_size(c.oid) AS bytes
                 FROM pg_class c
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = 'public' AND c.relkind = 'r'
                 ORDER BY bytes DESC
                 LIMIT :limit",
                ['limit' => $limit],
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Zero the pg_stat_statements accumulators so the next reading measures a
     * fresh window. False when it could not be done, including when the
     * extension is not there to reset.
     */
    public function resetStatStatements(): bool
    {
        try {
            $this->connection->executeStatement('SELECT pg_stat_statements_reset()');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The two statement views differ only in what they order by and whether
     * they floor the mean, so they share one query.
     *
     * $orderColumn is interpolated because ORDER BY takes an identifier and an
     * identifier cannot be bound. It is safe by construction: the only two
     * values that reach here are the literals in the two public methods above,
     * and nothing on this class accepts a column name from a caller.
     *
     * shared_blks_hit/read may be absent on very old versions; COALESCE guards
     * the hit-ratio expression regardless.
     *
     * The extension is checked before the view is read rather than relying on
     * the catch below. A missing relation is an error, and an error inside an
     * open transaction poisons it for every statement after — so "the operator
     * has not enabled pg_stat_statements" must not be discovered by failing.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchStatements(string $orderColumn, int $limit, ?float $meanFloorMs): array
    {
        if (false === $this->hasStatStatements()) {
            return [];
        }

        $floorClause = null === $meanFloorMs ? '' : 'AND s.mean_exec_time >= :floor';

        $sql = "SELECT
                    s.query,
                    s.calls,
                    s.mean_exec_time,
                    s.max_exec_time,
                    s.total_exec_time,
                    s.rows,
                    CASE
                        WHEN (COALESCE(s.shared_blks_hit, 0) + COALESCE(s.shared_blks_read, 0)) > 0
                        THEN (COALESCE(s.shared_blks_hit, 0)::float
                              / (COALESCE(s.shared_blks_hit, 0) + COALESCE(s.shared_blks_read, 0))) * 100
                        ELSE NULL
                    END AS hit_pct
                 FROM pg_stat_statements s
                 JOIN pg_database d ON d.oid = s.dbid
                 WHERE d.datname = current_database()
                   AND s.query NOT LIKE '%pg_stat_statements%'
                   {$floorClause}
                 ORDER BY s.{$orderColumn} DESC
                 LIMIT :limit";

        $params = ['limit' => $limit];

        if (null !== $meanFloorMs) {
            $params['floor'] = $meanFloorMs;
        }

        try {
            return $this->connection->fetchAllAssociative($sql, $params);
        } catch (\Throwable) {
            // Column-name mismatch on an unexpected PG version, or a
            // permissions issue — degrade to empty rather than 500.
            return [];
        }
    }
}
