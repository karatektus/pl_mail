<?php

declare(strict_types=1);

namespace App\Jmap\Push;

use App\Domain\Enum\PushDeliveryOutcome;
use App\Entity\Push\PushDelivery;
use App\Entity\User\PushSubscription;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes down what happened to a push, for the admin page and for the user's
 * own device list.
 *
 * **Called from inside the senders, rather than being a decorator around
 * PushSenderInterface — and that is a decision, not the shortest path.** A
 * decorator is the obvious shape: the registry takes a tagged iterable, so
 * wrapping each sender and recording around `send()` would need no change to
 * either implementation. It was rejected because of what `send()` returns. The
 * interface deliberately collapses "refused", "unreachable" and "the
 * subscription has just been removed" into one bool — the docblock says so, and
 * it is right, because no caller does anything different about them. A
 * decorator therefore sees `false` and nothing else. It could not record the
 * HTTP status, it could not record `UNREGISTERED` versus `QUOTA_EXCEEDED` —
 * which is the difference between a dead device and a Firebase outage — and it
 * could only guess at "destroyed" by asking the EntityManager afterwards
 * whether the row it was handed still exists. A log of "it failed" is a log
 * nobody opens twice.
 *
 * Widening the interface to return a result object was the alternative to both.
 * That was rejected as well: every caller of `send()` wants the bool, the
 * senders are the only place the vocabulary exists, and an interface shaped by
 * its logging is an interface shaped by the wrong concern.
 *
 * **Persists AND flushes**, unlike StateManager, which only persists. There is
 * no enclosing unit of work to ride out on: the skip paths return before the
 * senders reach a flush of their own, and the destroy path is followed
 * immediately by a `remove()` whose flush must not be the first thing that
 * commits this row — a record of an endpoint being retired that is written in
 * the same transaction as the retirement is a record that disappears if the
 * delete fails.
 *
 * A failure to record is swallowed with a warning. Monitoring that can break
 * delivery is worse than no monitoring, and the caller is in the middle of the
 * one thing this table exists to observe.
 */
final readonly class PushDeliveryRecorder
{
    /**
     * The `detail` column's width. Truncation happens here rather than at the
     * database, because an over-long exception message would otherwise fail the
     * INSERT and lose the record of the very failure it describes.
     */
    private const int MAX_DETAIL = 128;

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string,mixed> $payload the object that was (or would have been)
     *                                     sent; only its `@type` is read, and only
     *                                     that is stored
     * @param float               $startedAt the microtime(true) the attempt began at,
     *                                       taken by the caller so the measurement
     *                                       covers the request rather than the
     *                                       bookkeeping after it
     */
    public function record(
        PushSubscription    $subscription,
        array               $payload,
        PushDeliveryOutcome $outcome,
        ?string             $detail,
        float               $startedAt,
    ): void {
        $type = $payload['@type'] ?? null;

        try {
            $this->em->persist(new PushDelivery(
                $subscription->usr,
                $subscription->deviceClientId,
                $subscription->transport,
                is_string($type) && '' !== $type ? $type : null,
                $outcome,
                $this->shorten($detail),
                (int) round((microtime(true) - $startedAt) * 1000),
            ));

            $this->em->flush();
        } catch (\Throwable $exception) {
            $this->logger->warning('Push: the delivery could not be recorded', [
                'deviceClientId' => $subscription->deviceClientId,
                'outcome'        => $outcome->value,
                'error'          => $exception->getMessage(),
                'exception'      => $exception,
            ]);
        }
    }

    private function shorten(?string $detail): ?string
    {
        if (null === $detail || '' === $detail) {
            return null;
        }

        return mb_substr($detail, 0, self::MAX_DETAIL);
    }
}
