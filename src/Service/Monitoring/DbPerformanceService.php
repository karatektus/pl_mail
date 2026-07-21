<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use Doctrine\DBAL\Connection;

/**
 * Postgres performance read-model for the admin dashboard.
 *
 * Slow-query aggregation relies on the pg_stat_statements contrib extension
 * (one-time shared_preload_libraries + CREATE EXTENSION). Everything else —
 * live activity, cache-hit ratio, connections, transaction stats — uses core
 * catalog views and works unconditionally. When the extension is absent the
 * slow-query methods return an empty set and isStatStatementsAvailable()
 * reports false so the UI can prompt to enable it.
 */
final class DbPerformanceService
{
    /** Only surface statements slower than this mean (ms) to cut noise. */
    private const float SLOW_MEAN_MS = 5.0;

    /** Display cap for query text — full text is rarely needed on a board. */
    private const int QUERY_PREVIEW_LEN = 400;

    private ?bool $statStatementsAvailable = null;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function isStatStatementsAvailable(): bool
    {
        if (null !== $this->statStatementsAvailable) {
            return $this->statStatementsAvailable;
        }

        try {
            $available = $this->connection->fetchOne(
                "SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements')",
            );

            $this->statStatementsAvailable = (bool) $available;
        } catch (\Throwable) {
            $this->statStatementsAvailable = false;
        }

        return $this->statStatementsAvailable;
    }

    /**
     * Top statements by mean execution time (the "which queries are slow"
     * view), scoped to the current database.
     *
     * @return list<array{query: string, calls: int, meanMs: float, maxMs: float, totalMs: float, rows: int, hitPct: float|null}>
     */
    public function slowestByMean(int $limit = 20): array
    {
        return $this->fetchStatements('mean_exec_time', $limit, true);
    }

    /**
     * Top statements by cumulative time (the "where does the DB spend its
     * time overall" view — a fast query called millions of times lands here
     * but not in slowestByMean).
     *
     * @return list<array{query: string, calls: int, meanMs: float, maxMs: float, totalMs: float, rows: int, hitPct: float|null}>
     */
    public function heaviestByTotal(int $limit = 20): array
    {
        return $this->fetchStatements('total_exec_time', $limit, false);
    }

    /**
     * Currently executing (non-idle) backends, longest-running first. Needs
     * no extension — this is live activity, the thing to look at when the DB
     * feels stuck right now.
     *
     * @return list<array{pid: int, state: string, waitEvent: string|null, durationSeconds: int|null, query: string}>
     */
    public function activeQueries(): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
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
                 LIMIT 25",
            );
        } catch (\Throwable) {
            return [];
        }

        $active = [];

        foreach ($rows as $row) {
            $waitEvent = null;

            if (null !== $row['wait_event']) {
                $waitEvent = trim(((string) ($row['wait_event_type'] ?? '')) . ':' . (string) $row['wait_event'], ':');
            }

            $active[] = [
                'pid'             => (int) $row['pid'],
                'state'           => (string) $row['state'],
                'waitEvent'       => '' !== (string) $waitEvent ? $waitEvent : null,
                'durationSeconds' => null !== $row['duration_seconds'] ? (int) $row['duration_seconds'] : null,
                'query'           => $this->truncate((string) $row['query']),
            ];
        }

        return $active;
    }

    /**
     * Database-wide health gauges from pg_stat_database for the current DB.
     *
     * @return array{connections: int, cacheHitPct: float|null, commits: int, rollbacks: int, rollbackPct: float|null, deadlocks: int, tempFiles: int, tempBytes: int}
     */
    public function healthGauges(): array
    {
        $empty = [
            'connections' => 0,
            'cacheHitPct' => null,
            'commits'     => 0,
            'rollbacks'   => 0,
            'rollbackPct' => null,
            'deadlocks'   => 0,
            'tempFiles'   => 0,
            'tempBytes'   => 0,
        ];

        try {
            $row = $this->connection->fetchAssociative(
                "SELECT
                    numbackends,
                    xact_commit,
                    xact_rollback,
                    blks_read,
                    blks_hit,
                    deadlocks,
                    temp_files,
                    temp_bytes
                 FROM pg_stat_database
                 WHERE datname = current_database()",
            );
        } catch (\Throwable) {
            return $empty;
        }

        if (false === $row) {
            return $empty;
        }

        $blksHit  = (int) $row['blks_hit'];
        $blksRead = (int) $row['blks_read'];
        $totalBlk = $blksHit + $blksRead;

        $commits   = (int) $row['xact_commit'];
        $rollbacks = (int) $row['xact_rollback'];
        $totalTxn  = $commits + $rollbacks;

        return [
            'connections' => (int) $row['numbackends'],
            'cacheHitPct' => $totalBlk > 0 ? round(($blksHit / $totalBlk) * 100, 2) : null,
            'commits'     => $commits,
            'rollbacks'   => $rollbacks,
            'rollbackPct' => $totalTxn > 0 ? round(($rollbacks / $totalTxn) * 100, 2) : null,
            'deadlocks'   => (int) $row['deadlocks'],
            'tempFiles'   => (int) $row['temp_files'],
            'tempBytes'   => (int) $row['temp_bytes'],
        ];
    }

    /**
     * Reset the pg_stat_statements accumulators so the next reading measures
     * a fresh window. No-op (returns false) when the extension is absent.
     */
    public function resetStatStatements(): bool
    {
        if (false === $this->isStatStatementsAvailable()) {
            return false;
        }

        try {
            $this->connection->executeStatement('SELECT pg_stat_statements_reset()');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @return list<array{query: string, calls: int, meanMs: float, maxMs: float, totalMs: float, rows: int, hitPct: float|null}>
     */
    private function fetchStatements(string $orderColumn, int $limit, bool $applyMeanFloor): array
    {
        if (false === $this->isStatStatementsAvailable()) {
            return [];
        }

        // shared_blks_hit/read may be absent on very old versions; COALESCE
        // guards the hit-ratio expression regardless.
        $meanFloor = true === $applyMeanFloor
            ? 'AND s.mean_exec_time >= :floor'
            : '';

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
                   {$meanFloor}
                 ORDER BY s.{$orderColumn} DESC
                 LIMIT :limit";

        $params = ['limit' => $limit];

        if (true === $applyMeanFloor) {
            $params['floor'] = self::SLOW_MEAN_MS;
        }

        try {
            $rows = $this->connection->fetchAllAssociative($sql, $params);
        } catch (\Throwable) {
            // Column-name mismatch on an unexpected PG version, or a
            // permissions issue — degrade to empty rather than 500.
            return [];
        }

        $statements = [];

        foreach ($rows as $row) {
            $statements[] = [
                'query'   => $this->truncate((string) $row['query']),
                'calls'   => (int) $row['calls'],
                'meanMs'  => round((float) $row['mean_exec_time'], 2),
                'maxMs'   => round((float) $row['max_exec_time'], 2),
                'totalMs' => round((float) $row['total_exec_time'], 2),
                'rows'    => (int) $row['rows'],
                'hitPct'  => null !== $row['hit_pct'] ? round((float) $row['hit_pct'], 1) : null,
            ];
        }

        return $statements;
    }

    private function truncate(string $query): string
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? $query);

        if (mb_strlen($query) <= self::QUERY_PREVIEW_LEN) {
            return $query;
        }

        return mb_substr($query, 0, self::QUERY_PREVIEW_LEN) . '…';
    }
}
