<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;

/**
 * Asks every long-running process to exit so its container is recreated.
 *
 * This is exactly what `messenger:stop-workers` does — write a timestamp into
 * a shared cache pool. Symfony's StopWorkerOnRestartSignalListener has each
 * Messenger worker compare that timestamp against its own start time on every
 * loop and exit when it is newer. Since every service in compose.yaml runs
 * with `restart: unless-stopped`, exiting IS restarting.
 *
 * Deliberately not `docker restart`: the app has no Docker socket, and giving
 * it one to solve this would be wildly disproportionate.
 *
 * ImapSuperviseCommand is not a Messenger worker, so it reads this signal
 * itself rather than being covered by the listener — same key, same meaning,
 * one button.
 *
 * The pool is `cache.messenger.restart_workers_signal`, which is shared across
 * containers. If it is ever reconfigured to a non-shared adapter (array, or a
 * per-container filesystem path) this silently stops working, because each
 * process would read its own copy.
 */
final readonly class WorkerRestartSignal
{
    public function __construct(
        #[Autowire(service: 'cache.messenger.restart_workers_signal')]
        private CacheItemPoolInterface $pool,
    ) {}

    /**
     * @return float the timestamp written, so callers can report it back
     */
    public function request(): float
    {
        $now  = microtime(true);
        $item = $this->pool->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY);

        $item->set($now);
        $this->pool->save($item);

        return $now;
    }

    /**
     * When a restart was last asked for, or null if never.
     */
    public function requestedAt(): ?float
    {
        $item = $this->pool->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY);

        if (false === $item->isHit()) {
            return null;
        }

        $value = $item->get();

        if (false === is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * True when a restart was requested after the given process start time —
     * the check a non-Messenger long-running process makes for itself.
     */
    public function isRequestedSince(float $processStartedAt): bool
    {
        $requestedAt = $this->requestedAt();

        return null !== $requestedAt && $requestedAt > $processStartedAt;
    }
}
