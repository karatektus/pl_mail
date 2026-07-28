<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Listener;

use App\Service\Monitoring\ProcessHeartbeatService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Beats a heartbeat for every running messenger worker (one container = one
 * worker in this setup). WorkerRunningEvent fires on every loop iteration —
 * including idle polls — so it is throttled here.
 *
 * Keyed by APP_CONTAINER_NAME rather than the hostname: workers exit on
 * --time-limit and a recreated container gets a fresh hostname, which left a
 * new stale row behind on every cycle. The container name is stable across
 * restarts, so a worker reclaims its own row. The row is dropped again on a
 * clean stop, and ProcessHeartbeatService::pruneStale() is the backstop for
 * rows left behind by hard kills.
 *
 * Self-contained: no changes to the worker service or its command needed.
 */
final class WorkerHeartbeatListener
{
    private const int INTERVAL_SECONDS = 30;

    private int $lastBeatAt = 0;

    public function __construct(
        private readonly ProcessHeartbeatService $heartbeats,
        #[Autowire(env: 'APP_CONTAINER_NAME')]
        private readonly string $containerName = 'worker',
    ) {}

    #[AsEventListener(event: WorkerRunningEvent::class)]
    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        $now = time();

        if (($now - $this->lastBeatAt) < self::INTERVAL_SECONDS) {
            return;
        }

        $this->lastBeatAt = $now;

        $this->heartbeats->beat(
            ProcessHeartbeatService::TYPE_MESSENGER_WORKER,
            $this->beatKey(),
            ['idle' => $event->isWorkerIdle()],
        );
    }

    #[AsEventListener(event: WorkerStoppedEvent::class)]
    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        $this->heartbeats->clear(
            ProcessHeartbeatService::TYPE_MESSENGER_WORKER,
            $this->beatKey(),
        );
    }

    private function beatKey(): string
    {
        if ('' !== $this->containerName) {
            return $this->containerName;
        }

        $hostname = gethostname();

        return false !== $hostname ? $hostname : 'worker';
    }
}
