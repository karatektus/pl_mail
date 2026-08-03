<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use App\Repository\Monitoring\MessengerQueueRepository;
use DateTimeInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Throwable;

/**
 * Read-side monitoring of the doctrine messenger transport plus management
 * of the failure transport (list / retry / delete).
 *
 * Queue depth comes from MessengerQueueRepository. Failed messages go through
 * the transport's ListableReceiver so envelopes keep their stamps.
 */
final class QueueMonitor
{
    /** Public properties of a message worth showing in the queue panel. */
    private const int SUMMARY_MAX_PROPERTIES = 6;

    public function __construct(
        private readonly MessengerQueueRepository $queues,
        #[Autowire(service: 'messenger.transport.failed')]
        private readonly ReceiverInterface   $failureTransport,
        #[Autowire(service: 'messenger.transport.native_php_serializer')]
        private readonly SerializerInterface $serializer,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * @return list<array{queue: string, pending: int, running: int, oldestAgeSeconds: int|null, waitingSinceSeconds: int|null, runningForSeconds: int|null}>
     */
    public function queueStats(): array
    {
        $rows = $this->queues->statsByQueue();

        $stats = [];

        foreach ($rows as $row) {
            $stats[] = [
                'queue'   => (string) $row['queue_name'],
                'pending' => (int) $row['pending'],
                'running' => (int) $row['running'],
                // When the oldest waiting message became eligible.
                'oldestAgeSeconds' => $this->ageOf($row['oldest']),
                // How long it has existed, which is the number somebody means
                // by "this has been queued for two hours" — a retried message
                // keeps pushing its available_at forward, but not this.
                'waitingSinceSeconds' => $this->ageOf($row['oldest_created']),
                // How long the longest-held message has been with a worker. A
                // number that keeps climbing here is a stuck handler, which
                // reads as an idle queue everywhere else.
                'runningForSeconds' => $this->ageOf($row['oldest_delivered']),
            ];
        }

        return $stats;
    }

    /**
     * Individual queued messages: what a worker is holding right now, and what
     * is behind it.
     *
     * Envelopes are decoded rather than read as SQL because the message class
     * and its payload exist only inside the serialised blob. A body that no
     * longer deserialises — class renamed, payload older than a change — is
     * reported as such rather than skipped: an undecodable message is stuck
     * forever, and that is exactly what this panel is for.
     *
     * @return list<array{id: string, queue: string, class: string, summary: string, state: string, ageSeconds: int, runningForSeconds: int|null, availableInSeconds: int|null, retries: int}>
     */
    public function queuedMessages(int $limit = 100): array
    {
        $messages = [];

        foreach ($this->queues->messages($limit) as $row) {
            $envelope  = $this->decode($row);
            $delivered = $row['delivered_at'];

            $availableIn = max(0, (int) strtotime((string) $row['available_at']) - time());

            $messages[] = [
                'id'    => (string) $row['id'],
                'queue' => (string) $row['queue_name'],
                'class' => null === $envelope
                    ? 'undecodable'
                    : $this->shortName($envelope->getMessage()::class),
                'summary' => null === $envelope ? '' : $this->summarise($envelope->getMessage()),
                'state'   => match (true) {
                    null !== $delivered => 'running',
                    $availableIn > 0    => 'scheduled',
                    default             => 'waiting',
                },
                'ageSeconds'         => $this->ageOf($row['created_at']) ?? 0,
                'runningForSeconds'  => $this->ageOf($delivered),
                'availableInSeconds' => $availableIn > 0 ? $availableIn : null,
                'retries'            => $envelope?->last(RedeliveryStamp::class)?->getRetryCount() ?? 0,
            ];
        }

        return $messages;
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
     * Seconds since a transport timestamp, or null if there is none.
     *
     * Clamped at zero: available_at sits in the future while a retry backs
     * off, and "waiting -12s" is not a thing worth rendering.
     */
    private function ageOf(?string $timestamp): ?int
    {
        if (null === $timestamp) {
            return null;
        }

        return max(0, time() - (int) strtotime($timestamp));
    }

    /**
     * @param array{body: string, headers: string} $row
     */
    private function decode(array $row): ?Envelope
    {
        try {
            return $this->serializer->decode([
                'body'    => $row['body'],
                'headers' => (array) json_decode($row['headers'], true, flags: JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The message's own public state, as one short line.
     *
     * Reflection rather than a class-by-class formatter: every message here is
     * a readonly value object of promoted public scalars, and a per-class
     * switch would be a second place to remember whenever one is added. Only
     * scalars are printed — a payload object would be noise at this size.
     */
    private function summarise(object $message): string
    {
        $parts = [];

        foreach (get_object_vars($message) as $name => $value) {
            if (count($parts) >= self::SUMMARY_MAX_PROPERTIES) {
                break;
            }

            $parts[] = $name . ': ' . match (true) {
                is_bool($value)   => $value ? 'true' : 'false',
                is_string($value) => mb_strimwidth($value, 0, 60, '…'),
                is_scalar($value) => (string) $value,
                is_array($value)  => count($value) . ' items',
                null === $value   => '—',
                default           => $this->shortName($value::class),
            };
        }

        return implode(' · ', $parts);
    }

    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return false === $position ? $fqcn : substr($fqcn, $position + 1);
    }

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
