<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * Read-side monitoring of the doctrine messenger transport plus management
 * of the failure transport (list / retry / delete).
 *
 * Queue stats query messenger_messages directly via DBAL — cheap, and the
 * doctrine transport's schema is stable. Failed messages go through the
 * transport's ListableReceiver so envelopes keep their stamps.
 */
final class QueueMonitor
{
    public function __construct(
        private readonly Connection          $connection,
        #[Autowire(service: 'messenger.transport.failed')]
        private readonly ReceiverInterface   $failureTransport,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * @return list<array{queue: string, pending: int, oldestAgeSeconds: int|null}>
     */
    public function queueStats(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT queue_name, COUNT(*) AS pending, MIN(available_at) AS oldest
             FROM messenger_messages
             WHERE delivered_at IS NULL
             GROUP BY queue_name
             ORDER BY queue_name',
        );

        $stats = [];

        foreach ($rows as $row) {
            $oldestAge = null;

            if (null !== $row['oldest']) {
                $oldestAge = max(0, time() - (int) strtotime((string) $row['oldest']));
            }

            $stats[] = [
                'queue'            => (string) $row['queue_name'],
                'pending'          => (int) $row['pending'],
                'oldestAgeSeconds' => $oldestAge,
            ];
        }

        return $stats;
    }

    /**
     * @return list<array{id: string, class: string, error: string|null, failedAt: DateTimeInterface|null}>
     */
    public function failedMessages(int $limit = 50): array
    {

        if (false === $this->failureTransport instanceof ListableReceiverInterface) {
            return [];
        }

        $failed = [];

        foreach ($this->failureTransport->all($limit) as $envelope) {
            /** @var TransportMessageIdStamp|null $idStamp */
            $idStamp = $envelope->last(TransportMessageIdStamp::class);

            /** @var ErrorDetailsStamp|null $errorStamp */
            $errorStamp = $envelope->last(ErrorDetailsStamp::class);

            /** @var RedeliveryStamp|null $redeliveryStamp */
            $redeliveryStamp = $envelope->last(RedeliveryStamp::class);

            if (null === $idStamp) {
                continue;
            }

            $failed[] = [
                'id'       => (string) $idStamp->getId(),
                'class'    => $envelope->getMessage()::class,
                'error'    => $errorStamp?->getExceptionMessage(),
                'failedAt' => $redeliveryStamp?->getRedeliveredAt(),
            ];
        }

        return $failed;
    }

    /**
     * Re-dispatch a failed message onto the bus and drop it from the
     * failure transport. Stamps are intentionally not carried over — the
     * message re-enters routing as a fresh dispatch.
     */
    public function retry(string $id): bool
    {
        $envelope = $this->find($id);

        if (null === $envelope) {
            return false;
        }

        $this->bus->dispatch($envelope->getMessage());
        $this->failureTransport->reject($envelope);

        return true;
    }

    public function remove(string $id): bool
    {
        $envelope = $this->find($id);

        if (null === $envelope) {
            return false;
        }

        $this->failureTransport->reject($envelope);

        return true;
    }

    /**
     * Re-dispatch every failed message. A bad deploy fails messages in bulk,
     * and clearing that one row at a time is not a realistic recovery.
     *
     * The envelope list is materialised before anything is dispatched:
     * retried messages can fail again and land straight back in this
     * transport, so iterating the live receiver could keep finding work and
     * never terminate.
     *
     * @return int messages re-dispatched
     */
    public function retryAll(): int
    {
        $retried = 0;

        foreach ($this->allFailed() as $envelope) {
            $this->bus->dispatch($envelope->getMessage());
            $this->failureTransport->reject($envelope);
            $retried++;
        }

        return $retried;
    }

    /**
     * Drop every failed message without re-dispatching. Destructive: the
     * messages are gone, so the UI guards this with a confirmation.
     *
     * @return int messages dropped
     */
    public function purgeAll(): int
    {
        $purged = 0;

        foreach ($this->allFailed() as $envelope) {
            $this->failureTransport->reject($envelope);
            $purged++;
        }

        return $purged;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Every envelope currently in the failure transport, as a materialised
     * list. See retryAll() for why this must not stay lazy.
     *
     * @return list<Envelope>
     */
    private function allFailed(): array
    {
        if (false === $this->failureTransport instanceof ListableReceiverInterface) {
            return [];
        }

        return iterator_to_array($this->failureTransport->all(), false);
    }

    private function find(string $id): ?Envelope
    {
        if (false === $this->failureTransport instanceof ListableReceiverInterface) {
            return null;
        }

        return $this->failureTransport->find($id);
    }
}
