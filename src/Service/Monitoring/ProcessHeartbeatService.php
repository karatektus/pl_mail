<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

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

    public function __construct(
        private readonly Connection $connection,
    ) {}

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

    public function pruneOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM process_heartbeat WHERE last_beat_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );
    }
}
