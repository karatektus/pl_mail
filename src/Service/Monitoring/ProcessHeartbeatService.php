<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
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

    /**
     * Not a process at all: the last time `app:push:renew` finished a run.
     *
     * A scheduled command that stops being scheduled is silent by construction
     * — MaintenanceSchedule runs it at 04:00 daily, and a scheduler worker that
     * is down does not fail, it simply does not fire, which logs nothing and
     * raises nothing. The only way to notice is to record the runs that DO
     * happen and look at how long ago the last one was.
     */
    public const string TYPE_PUSH_RENEW = 'push-renew';

    /** Seconds after which a heartbeat counts as stale, per process type. */
    public const array STALE_THRESHOLDS = [
        self::TYPE_IMAP_IDLE        => 2100, // just over the 29-min IDLE reissue
        self::TYPE_IMAP_SUPERVISE   => 300,
        self::TYPE_MESSENGER_WORKER => 120,  // listener beats every 30s
    ];

    /**
     * Types that record "the last time this finished" rather than "this process
     * is alive right now".
     *
     * Held apart from STALE_THRESHOLDS because the two are swept differently.
     * A liveness row that has gone quiet is worthless and gets reaped; a
     * milestone row that has gone quiet IS the finding — deleting the evidence
     * that renewal last ran five days ago would take away the one thing that
     * says the scheduler is down. See pruneStale().
     */
    public const array MILESTONE_THRESHOLDS = [
        // 25 hours: the command is scheduled daily, so a run is due every 24,
        // and the extra hour is slack for a queue that was busy at 04:00. Any
        // longer and a whole missed day would still read as healthy.
        self::TYPE_PUSH_RENEW => 90000,
    ];

    public const int DEFAULT_STALE_THRESHOLD = 600;

    /**
     * How many stale-thresholds a row may lag before pruneStale() removes it
     * entirely. Wide enough that a briefly-wedged process still shows up red
     * on the dashboard before it disappears.
     */
    private const int PRUNE_STALE_FACTOR = 4;

    /** Seconds between beats from a handler that is busy. Matches the listener's. */
    private const int BUSY_INTERVAL_SECONDS = 30;

    private int $lastBusyBeatAt = 0;

    public function __construct(
        private readonly ProcessHeartbeatRepository $heartbeats,
        #[Autowire(env: 'APP_CONTAINER_NAME')]
        private readonly string $containerName = 'worker',
    ) {}

    /**
     * A beat from inside a handler that is going to be a while.
     *
     * WHY THE LISTENER IS NOT ENOUGH. WorkerHeartbeatListener beats on
     * WorkerRunningEvent, which fires between messages — so a worker beats
     * happily while idle and goes silent the moment it has something long to
     * do. TYPE_MESSENGER_WORKER goes stale after 120 seconds, and this
     * application now has handlers that legitimately run for many minutes: a
     * full-conversation summary, a mailbox re-file, a run of model calls over
     * recent mail. Every one of them makes the admin dashboard report a dead
     * worker while the worker is doing exactly what it was asked to.
     *
     * That is the worst shape of monitoring failure — it cries wolf precisely
     * when the system is working hardest, so the one time the indicator is
     * red for a real reason it has already been learned to ignore.
     *
     * Throttled like the listener, so a handler may call it as often as it
     * likes: per batch, per token, per loop. Cheap enough to be unconditional
     * and idempotent enough that a double call costs one upsert.
     */
    public function beatWhileBusy(): void
    {
        $now = time();

        if (($now - $this->lastBusyBeatAt) < self::BUSY_INTERVAL_SECONDS) {
            return;
        }

        $this->lastBusyBeatAt = $now;

        $this->beat(self::TYPE_MESSENGER_WORKER, $this->containerName, ['busy' => true]);
    }

    public static function staleThreshold(string $type): int
    {
        return self::STALE_THRESHOLDS[$type]
            ?? self::MILESTONE_THRESHOLDS[$type]
            ?? self::DEFAULT_STALE_THRESHOLD;
    }

    /**
     * Record that a scheduled job completed a run, now.
     *
     * Deliberately the same storage as a process heartbeat rather than a new
     * mechanism: this is one row saying "X was last heard from at T", which is
     * exactly what the table already is, and the admin dashboard renders it
     * with no changes. What differs is only the sweeping — see
     * MILESTONE_THRESHOLDS.
     *
     * @param array<string,mixed>|null $meta
     */
    public function recordRun(string $type, ?array $meta = null): void
    {
        $this->beat($type, 'main', $meta);
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
     *
     * Milestone rows are exempt, and that exemption is the point of the
     * distinction. A liveness row that stopped beating describes a process that
     * no longer exists, so it is noise. A milestone row that stopped beating
     * describes a scheduled job that stopped running — the finding itself — and
     * reaping it would delete the only record that renewal has not run since
     * Tuesday, replacing a health page that says so with one that says nothing.
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

            // Milestone types join the "known" list purely so this sweep leaves
            // them alone: it deletes rows whose type is NOT in the list.
            $deleted += $this->heartbeats->deleteStalerThanForUnknownTypes(
                [...array_keys(self::STALE_THRESHOLDS), ...array_keys(self::MILESTONE_THRESHOLDS)],
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
