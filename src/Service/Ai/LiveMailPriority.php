<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Repository\Monitoring\MessengerQueueRepository;
use App\Service\Mail\PostIngest\EnrichmentRouter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lets mail that has just arrived go ahead of bulk classification at the model
 * host.
 *
 * WHY A QUEUE OF ITS OWN WAS NOT ENOUGH
 * ─────────────────────────────────────
 * Live mail has its own transport and its own worker, so nothing bulk is ever
 * in front of it IN THE QUEUE. But the two workers end at the same model host,
 * and that host answers one request at a time: a worker that is free to pick a
 * live message up at once still waits for whatever the bulk worker has in
 * flight, and then for the next one, because the bulk worker's following
 * request is already on its way.
 *
 * So bulk classification asks before each call whether the live queue has
 * anything due, and stands aside while it does. The cost to a held message is
 * then at most the one call that was already in flight when it arrived — which
 * is the difference between a hold measured in seconds and one that runs out.
 *
 * BOUNDED, AND FAILS OPEN. The wait has a ceiling so a live queue that never
 * drains — a dead worker — delays an import rather than stopping it. And a
 * queue that cannot be read answers "go ahead": this is a courtesy between
 * two workers, and it must never be the reason no mail is classified at all.
 * That includes every transport that is not the doctrine one, where the table
 * this reads does not exist.
 */
final readonly class LiveMailPriority
{
    /** The longest one bulk call is put off, in seconds. */
    private const int MAX_WAIT_SECONDS = 20;

    /** How often to look again. Short: the thing waited for takes a second or two. */
    private const int POLL_MICROSECONDS = 250_000;

    public function __construct(
        private MessengerQueueRepository $queues,
        private LoggerInterface          $logger,
    ) {
    }

    /**
     * Returns once the live queue is quiet, or once it has been waited on for
     * long enough.
     */
    public function standAside(): void
    {
        $waited = 0;

        while ($waited < self::MAX_WAIT_SECONDS * 1_000_000) {
            if (false === $this->liveMailWaiting()) {
                return;
            }

            usleep(self::POLL_MICROSECONDS);

            $waited += self::POLL_MICROSECONDS;
        }
    }

    private function liveMailWaiting(): bool
    {
        try {
            return $this->queues->countActiveOn(EnrichmentRouter::LIVE) > 0;
        } catch (Throwable $exception) {
            $this->logger->debug('LiveMailPriority: could not read the live queue, not waiting', [
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return false;
        }
    }
}
