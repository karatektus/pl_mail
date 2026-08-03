<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use App\Repository\Monitoring\PostgresStatusRepository;

/**
 * Postgres performance read-model for the admin dashboard.
 *
 * The catalogue reads themselves live in PostgresStatusRepository; what is left
 * here is the shaping the dashboard needs — deriving ratios, rounding, and
 * cutting query text down to something that fits on a board.
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
        private readonly PostgresStatusRepository $status,
    ) {}

    /**
     * Memoised: an admin page asks this once per panel, and whether an
     * extension is installed cannot change inside one request.
     */
    public function isStatStatementsAvailable(): bool
    {
        return $this->statStatementsAvailable ??= $this->status->hasStatStatements();
    }

    /**
     * Top statements by mean execution time (the "which queries are slow"
     * view), scoped to the current database.
     *
     * @return list<array{query: string, calls: int, meanMs: float, maxMs: float, totalMs: float, rows: int, hitPct: float|null}>
     */
    public function slowestByMean(int $limit = 20): array
    {
        return $this->shapeStatements($this->status->statementsSlowestByMean($limit, self::SLOW_MEAN_MS));
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
        return $this->shapeStatements($this->status->statementsHeaviestByTotal($limit));
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
        $active = [];

        foreach ($this->status->activeBackends() as $row) {
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
        $row = $this->status->databaseStats();

        if (null === $row) {
            return [
                'connections' => 0,
                'cacheHitPct' => null,
                'commits'     => 0,
                'rollbacks'   => 0,
                'rollbackPct' => null,
                'deadlocks'   => 0,
                'tempFiles'   => 0,
                'tempBytes'   => 0,
            ];
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

        return $this->status->resetStatStatements();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array{query: string, calls: int, meanMs: float, maxMs: float, totalMs: float, rows: int, hitPct: float|null}>
     */
    private function shapeStatements(array $rows): array
    {
        $statements = [];

        foreach ($rows as $row) {
            $statements[] = [
                'query'   => (string) $row['query'],
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
