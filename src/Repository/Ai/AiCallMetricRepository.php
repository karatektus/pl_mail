<?php

declare(strict_types=1);

namespace App\Repository\Ai;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Throwable;

/**
 * What the model calls have actually cost, per feature and per model.
 *
 * Not a ServiceEntityRepository, and it never will be: there is no entity
 * behind ai_call_metric on purpose (see AiCallRecorder), and everything asked
 * of it is an aggregate that no object graph would help with.
 *
 * PERCENTILES IN THE DATABASE, NOT IN PHP
 * ───────────────────────────────────────
 * percentile_cont is not expressible in DQL, and the alternative — pulling
 * every row of a window into PHP to sort it — is a table scan and a sort per
 * page load, on the one table that grows on every arriving message once tab
 * sorting is on, and grows hard during a backfill. So: raw SQL, in a
 * repository, which is where the SQL in this project lives.
 *
 * WHY p95 AND NOT AN AVERAGE
 * ──────────────────────────
 * A mean over a window that contains one cold load reports a machine nobody
 * has. The median says what the box normally does; the 95th says what it does
 * when it is having a bad time, and the gap between them is the thing an
 * operator is actually looking for.
 *
 * NULLS ARE FILTERED, NEVER COALESCED
 * ───────────────────────────────────
 * An embedding has no generation phase, so its eval columns are null. Reading
 * those as zero would put a zero tokens-per-second sample into the middle of
 * every percentile and drag p50 toward the floor — a panel confidently
 * reporting that a working model is slow. FILTER excludes them instead, and a
 * bucket with nothing to say answers null rather than a made-up number.
 *
 * Nothing here throws. A metrics read that fails is a gap in a panel, never a
 * 500 on the admin page that was going to draw it.
 */
final readonly class AiCallMetricRepository
{
    /**
     * What counts as having paid a cold load: one second, in nanoseconds.
     *
     * A resident model answers with load_duration in the single-digit
     * milliseconds. The measured cold load for a 20 GiB model on the target
     * hardware is around thirteen SECONDS. Anywhere in between is a
     * comfortable place to put the line — it is not a close call, and it does
     * not need to be tuned.
     */
    private const int COLD_LOAD_NS = 1_000_000_000;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<array{bucket: string, calls: int, errors: int, coldLoads: int,
     *     genTokensPerSecondP50: float|null, genTokensPerSecondP95: float|null,
     *     promptTokensPerSecondP50: float|null, loadMsP50: float|null}>
     */
    public function perFeatureSince(DateTimeImmutable $since): array
    {
        return $this->aggregate('feature', $since);
    }

    /**
     * @return list<array{bucket: string, calls: int, errors: int, coldLoads: int,
     *     genTokensPerSecondP50: float|null, genTokensPerSecondP95: float|null,
     *     promptTokensPerSecondP50: float|null, loadMsP50: float|null}>
     */
    public function perModelSince(DateTimeImmutable $since): array
    {
        return $this->aggregate('model', $since);
    }

    /**
     * The most recent call, for the panel's "right now" line.
     *
     * @return array{feature: string, model: string, succeeded: bool, errorKind: string|null,
     *     genTokensPerSecond: float|null, loadMs: float|null, at: string}|null
     */
    public function latest(): ?array
    {
        $sql = <<<'SQL'
            SELECT feature, model, succeeded, error_kind, created_at,
                   CASE WHEN eval_tokens > 0 AND eval_duration_ns > 0
                        THEN eval_tokens::double precision * 1e9 / eval_duration_ns::double precision
                   END AS gen_tps,
                   load_duration_ns
              FROM ai_call_metric
             ORDER BY id DESC
             LIMIT 1
        SQL;

        try {
            $row = $this->connection->fetchAssociative($sql);
        } catch (Throwable) {
            return null;
        }

        if (false === $row) {
            return null;
        }

        return [
            'feature'            => (string) $row['feature'],
            'model'              => (string) $row['model'],
            'succeeded'          => (bool) $row['succeeded'],
            'errorKind'          => null === $row['error_kind'] ? null : (string) $row['error_kind'],
            'genTokensPerSecond' => self::rate($row['gen_tps']),
            'loadMs'             => self::milliseconds($row['load_duration_ns']),
            'at'                 => (string) $row['created_at'],
        ];
    }

    /**
     * When a person last had a model working for them.
     *
     * Only the two interactive workloads, and that distinction is the whole
     * value of the row: a mail being indexed in the background is the backfill
     * itself, and a signal that counted it would report the backfill as a
     * reason to pause the backfill.
     *
     * Bounded by $since so the index (feature, created_at) does the work rather
     * than a scan back through every call this installation has ever made.
     * Answering null for "nothing recent" is the same answer as "nothing ever",
     * and the caller wants the same thing in both cases: carry on.
     */
    public function lastInteractiveCallAt(DateTimeImmutable $since): ?DateTimeImmutable
    {
        $sql = <<<'SQL'
            SELECT MAX(created_at)
              FROM ai_call_metric
             WHERE feature IN ('search_query', 'writing_help')
               AND created_at >= :since
        SQL;

        try {
            $value = $this->connection->fetchOne($sql, ['since' => $since], ['since' => Types::DATETIME_IMMUTABLE]);
        } catch (Throwable) {
            return null;
        }

        if (false === is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What a cold load actually costs on this box, in milliseconds.
     *
     * The number the panel needs to say "nothing is loaded; the next request
     * pays about thirteen seconds" — and it cannot come from loadMsP50, which
     * is the median of ALL loads. On a busy install almost every call finds the
     * model resident and reports single-digit milliseconds, so that median says
     * five where the answer is thirteen thousand.
     *
     * So: the median of the loads that were actually cold, over a deliberately
     * long window. Cold loads are rare on a healthy install — the point of the
     * keep-alive is that they should be — and this is a property of the
     * hardware and the model rather than of the last hour, so a month of them
     * is a better sample than a day of them.
     */
    public function typicalColdLoadMs(DateTimeImmutable $since): ?float
    {
        $sql = <<<'SQL'
            SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY load_duration_ns::double precision)
              FROM ai_call_metric
             WHERE created_at >= :since
               AND load_duration_ns > :coldLoadNs
        SQL;

        try {
            $value = $this->connection->fetchOne(
                $sql,
                ['since' => $since, 'coldLoadNs' => self::COLD_LOAD_NS],
                ['since' => Types::DATETIME_IMMUTABLE],
            );
        } catch (Throwable) {
            return null;
        }

        return self::milliseconds(false === $value || null === $value ? null : $value);
    }

    /**
     * Forget calls older than a date, and say how many were forgotten.
     *
     * The retention half of a table that grows on every arriving message once
     * tab sorting is on, and grows hard during a backfill — one row per
     * embedded message, a hundred thousand of them in an afternoon. Called from
     * a console command on a schedule and from nowhere else: a sweep inside a
     * web request would put an unbounded DELETE in front of somebody reading
     * their mail.
     */
    public function pruneOlderThan(DateTimeImmutable $cutoff): int
    {
        try {
            return (int) $this->connection->executeStatement(
                'DELETE FROM ai_call_metric WHERE created_at < :cutoff',
                ['cutoff' => $cutoff],
                ['cutoff' => Types::DATETIME_IMMUTABLE],
            );
        } catch (Throwable) {
            // Same bargain as everything else here: a metrics write that fails
            // is not worth a non-zero exit on a nightly maintenance run.
            return 0;
        }
    }

    /**
     * @return list<array{bucket: string, calls: int, errors: int, coldLoads: int,
     *     genTokensPerSecondP50: float|null, genTokensPerSecondP95: float|null,
     *     promptTokensPerSecondP50: float|null, loadMsP50: float|null}>
     */
    private function aggregate(string $column, DateTimeImmutable $since): array
    {
        // $column is interpolated because GROUP BY takes an IDENTIFIER and an
        // identifier cannot be bound. Safe by construction rather than by
        // escaping: the only two values that ever reach here are the two
        // literals in the public methods above, and nothing on this class
        // accepts a column name from a caller.
        $sql = <<<SQL
            SELECT
                {$column} AS bucket,
                COUNT(*)                                              AS calls,
                COUNT(*) FILTER (WHERE succeeded = false)             AS errors,
                COUNT(*) FILTER (WHERE load_duration_ns > :coldLoadNs) AS cold_loads,
                percentile_cont(0.5) WITHIN GROUP (
                    ORDER BY eval_tokens::double precision * 1e9 / eval_duration_ns::double precision
                ) FILTER (WHERE eval_tokens > 0 AND eval_duration_ns > 0)     AS gen_tps_p50,
                percentile_cont(0.95) WITHIN GROUP (
                    ORDER BY eval_tokens::double precision * 1e9 / eval_duration_ns::double precision
                ) FILTER (WHERE eval_tokens > 0 AND eval_duration_ns > 0)     AS gen_tps_p95,
                percentile_cont(0.5) WITHIN GROUP (
                    ORDER BY prompt_tokens::double precision * 1e9 / prompt_duration_ns::double precision
                ) FILTER (WHERE prompt_tokens > 0 AND prompt_duration_ns > 0) AS prompt_tps_p50,
                percentile_cont(0.5) WITHIN GROUP (
                    ORDER BY load_duration_ns::double precision
                ) FILTER (WHERE load_duration_ns IS NOT NULL)                 AS load_ns_p50
            FROM ai_call_metric
            WHERE created_at >= :since
            GROUP BY {$column}
            ORDER BY calls DESC, bucket
        SQL;

        try {
            $rows = $this->connection->fetchAllAssociative(
                $sql,
                ['since' => $since, 'coldLoadNs' => self::COLD_LOAD_NS],
                ['since' => Types::DATETIME_IMMUTABLE],
            );
        } catch (Throwable) {
            // Most likely the table is not there yet, on a container that came
            // up before the migration lock released.
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            // PostgreSQL answers COUNT(*) as bigint and percentile_cont as
            // double precision, and DBAL hands BOTH to PHP as strings. Cast
            // here, at the boundary, rather than leaving the panel to discover
            // it is sorting numbers alphabetically.
            $out[] = [
                'bucket'                   => (string) $row['bucket'],
                'calls'                    => (int) $row['calls'],
                'errors'                   => (int) $row['errors'],
                'coldLoads'                => (int) $row['cold_loads'],
                'genTokensPerSecondP50'    => self::rate($row['gen_tps_p50']),
                'genTokensPerSecondP95'    => self::rate($row['gen_tps_p95']),
                'promptTokensPerSecondP50' => self::rate($row['prompt_tps_p50']),
                'loadMsP50'                => self::milliseconds($row['load_ns_p50']),
            ];
        }

        return $out;
    }

    /**
     * A rate, or null when the bucket had nothing to measure.
     *
     * Null survives as null: "no embedding call has a generation phase" and
     * "the generation ran at zero tokens a second" are different statements and
     * the panel renders them differently.
     */
    private static function rate(mixed $value): ?float
    {
        if (null === $value) {
            return null;
        }

        return round((float) $value, 1);
    }

    /** Nanoseconds out of the column, milliseconds into the panel. */
    private static function milliseconds(mixed $value): ?float
    {
        if (null === $value) {
            return null;
        }

        return round((float) $value / 1_000_000, 1);
    }
}
