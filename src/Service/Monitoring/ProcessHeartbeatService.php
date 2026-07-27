<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Records liveness beats for long-running processes via raw DBAL upsert —
 * the same pattern as ContactRepository, and deliberately ORM-free so a
 * beat can never entangle with (or be lost to) a handler's EntityManager
 * state. A beat must also never take its host process down, so failures
 * are swallowed.
 */
final class ProcessHeartbeatService
{
    public const string TYPE_IMAP_IDLE        = 'imap-idle';
    public const string TYPE_IMAP_SUPERVISE   = 'imap-supervise';
    public const string TYPE_MESSENGER_WORKER = 'messenger-worker';

    /** Seconds after which a heartbeat counts as stale, per process type. */
    public const array STALE_THRESHOLDS = [
        self::TYPE_IMAP_IDLE        => 2100, // just over the 29-min IDLE reissue
        self::TYPE_IMAP_SUPERVISE   => 300,
        self::TYPE_MESSENGER_WORKER => 120,  // listener beats every 30s
    ];

    public const int DEFAULT_STALE_THRESHOLD = 600;

    /**
     * How many stale-thresholds a row may lag before pruneStale() removes it
     * entirely. Wide enough that a briefly-wedged process still shows up red
     * on the dashboard before it disappears.
     */
    private const int PRUNE_STALE_FACTOR = 4;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public static function staleThreshold(string $type): int
    {
        return self::STALE_THRESHOLDS[$type] ?? self::DEFAULT_STALE_THRESHOLD;
    }

    /**
     * @param array<string,mixed>|null $meta
     */
    public function beat(string $type, string $key, ?array $meta = null): void
    {
        try {
            $this->connection->executeStatement(
                'INSERT INTO process_heartbeat (type, beat_key, pid, last_beat_at, meta)
                 VALUES (:type, :key, :pid, NOW(), :meta)
                 ON CONFLICT (type, beat_key) DO UPDATE
                 SET pid = EXCLUDED.pid, last_beat_at = EXCLUDED.last_beat_at, meta = EXCLUDED.meta',
                [
                    'type' => $type,
                    'key'  => $key,
                    'pid'  => false !== getmypid() ? getmypid() : null,
                    'meta' => null === $meta ? null : json_encode($meta, JSON_PARTIAL_OUTPUT_ON_ERROR),
                ],
            );
        } catch (\Throwable) {
            // Heartbeats must never take the process down.
        }
    }

    /**
     * Drop a single row when its process shuts down cleanly, so the dashboard
     * stops showing an instance that deliberately went away. Like beat(), this
     * must never take the shutting-down process with it.
     */
    public function clear(string $type, string $key): void
    {
        try {
            $this->connection->executeStatement(
                'DELETE FROM process_heartbeat WHERE type = :type AND beat_key = :key',
                ['type' => $type, 'key' => $key],
            );
        } catch (\Throwable) {
            // Cleanup must never take the process down.
        }
    }

    /**
     * Drop every row of $type whose key is not in $liveKeys. Called by the
     * process that owns the full live set (the IDLE supervisor), so rows left
     * behind by killed children or removed mailboxes get reaped even though
     * nobody was around to clear() them.
     *
     * @param list<string> $liveKeys
     */
    public function clearOrphans(string $type, array $liveKeys): int
    {
        try {
            if ([] === $liveKeys) {
                return (int) $this->connection->executeStatement(
                    'DELETE FROM process_heartbeat WHERE type = :type',
                    ['type' => $type],
                );
            }

            return (int) $this->connection->executeStatement(
                'DELETE FROM process_heartbeat WHERE type = :type AND beat_key NOT IN (:keys)',
                ['type' => $type, 'keys' => $liveKeys],
                ['keys' => ArrayParameterType::STRING],
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Reap rows that are far past their type's stale threshold — the backstop
     * for instances that vanished without a clean shutdown and have no live
     * owner to reconcile them (e.g. a recreated worker container, which gets a
     * fresh key when APP_CONTAINER_NAME is unset and hostname is used).
     */
    public function pruneStale(): int
    {
        $deleted = 0;

        try {
            foreach (self::STALE_THRESHOLDS as $type => $threshold) {
                $deleted += (int) $this->connection->executeStatement(
                    'DELETE FROM process_heartbeat
                      WHERE type = :type
                        AND last_beat_at < NOW() - (:seconds * INTERVAL \'1 second\')',
                    ['type' => $type, 'seconds' => $threshold * self::PRUNE_STALE_FACTOR],
                );
            }

            $deleted += (int) $this->connection->executeStatement(
                'DELETE FROM process_heartbeat
                  WHERE type NOT IN (:known)
                    AND last_beat_at < NOW() - (:seconds * INTERVAL \'1 second\')',
                [
                    'known'   => array_keys(self::STALE_THRESHOLDS),
                    'seconds' => self::DEFAULT_STALE_THRESHOLD * self::PRUNE_STALE_FACTOR,
                ],
                ['known' => ArrayParameterType::STRING],
            );
        } catch (\Throwable) {
            // Best-effort: this runs from inside long-lived processes.
        }

        return $deleted;
    }

    public function pruneOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM process_heartbeat WHERE last_beat_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );
    }
}
