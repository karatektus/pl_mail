<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use App\Repository\Monitoring\ProcessHeartbeatRepository;

/**
 * Liveness policy for long-running processes: what counts as stale, per
 * process type, and when a row has lagged far enough to be reaped.
 *
 * The statements themselves live on ProcessHeartbeatRepository, which explains
 * why they are raw DBAL. What stays here is the part that is a decision rather
 * than a query — including that a beat must never take its host process down,
 * which is why every call below swallows what it throws.
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
        private readonly ProcessHeartbeatRepository $heartbeats,
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
            $pid = getmypid();

            $this->heartbeats->upsertBeat($type, $key, false !== $pid ? $pid : null, $meta);
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
            $this->heartbeats->deleteBeat($type, $key);
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
            return $this->heartbeats->deleteOrphans($type, $liveKeys);
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
                $deleted += $this->heartbeats->deleteStalerThan(
                    $type,
                    $threshold * self::PRUNE_STALE_FACTOR,
                );
            }

            $deleted += $this->heartbeats->deleteStalerThanForUnknownTypes(
                array_keys(self::STALE_THRESHOLDS),
                self::DEFAULT_STALE_THRESHOLD * self::PRUNE_STALE_FACTOR,
            );
        } catch (\Throwable) {
            // Best-effort: this runs from inside long-lived processes.
        }

        return $deleted;
    }

    public function pruneOlderThan(\DateTimeImmutable $cutoff): int
    {
        return $this->heartbeats->deleteOlderThan($cutoff);
    }
}
