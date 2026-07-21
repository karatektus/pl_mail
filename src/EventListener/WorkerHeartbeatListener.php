<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Monitoring\ProcessHeartbeatService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Beats a heartbeat for every running messenger worker, keyed by hostname
 * (one container = one worker in this setup). WorkerRunningEvent fires on
 * every loop iteration — including idle polls — so it is throttled here.
 *
 * Self-contained: no changes to the worker service or its command needed.
 */
#[AsEventListener(event: WorkerRunningEvent::class)]
final class WorkerHeartbeatListener
{
    private const int INTERVAL_SECONDS = 30;

    private int $lastBeatAt = 0;

    public function __construct(
        private readonly ProcessHeartbeatService $heartbeats,
    ) {}

    public function __invoke(WorkerRunningEvent $event): void
    {
        $now = time();

        if (($now - $this->lastBeatAt) < self::INTERVAL_SECONDS) {
            return;
        }

        $this->lastBeatAt = $now;

        $hostname = gethostname();

        $this->heartbeats->beat(
            ProcessHeartbeatService::TYPE_MESSENGER_WORKER,
            false !== $hostname ? $hostname : 'worker',
            ['idle' => $event->isWorkerIdle()],
        );
    }
}
