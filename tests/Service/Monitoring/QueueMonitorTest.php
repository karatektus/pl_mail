<?php

declare(strict_types=1);

namespace App\Tests\Service\Monitoring;

use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Service\Monitoring\QueueMonitor;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * The queue panel's read of the transport.
 *
 * It exists to tell a stuck queue from an empty one, and every number on it is
 * a claim about work that is or is not happening — so being wrong here is worse
 * than showing nothing, because somebody looks at it precisely when mail has
 * stopped arriving and decides whether to go digging.
 *
 * Written against a real messenger_messages table and real serialised
 * envelopes, since what is being tested is the reading of the transport's own
 * storage format: a hand-built fixture that agreed with the code would prove
 * nothing about the rows Messenger actually writes.
 */
final class QueueMonitorTest extends KernelTestCase
{
    private Connection $connection;
    private QueueMonitor $monitor;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->monitor    = $container->get(QueueMonitor::class);

        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM messenger_messages');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The distinction the whole panel rests on. "Pending" is the transport's
     * own `delivered_at IS NULL`; anything else is a worker holding a message
     * right now, and a queue with one of each looks completely different from a
     * queue with two of either.
     */
    public function testPendingAndRunningAreCountedApart(): void
    {
        $this->enqueue('ingest', createdAt: '-10 minutes');
        $this->enqueue('ingest', createdAt: '-5 minutes');
        $this->enqueue('ingest', createdAt: '-2 minutes', deliveredAt: '-1 minute');

        $stats = $this->statsFor('ingest');

        self::assertSame(2, $stats['pending']);
        self::assertSame(1, $stats['running']);
    }

    /**
     * Age is measured from creation, not from availability. A retried message
     * pushes its availability forward every attempt, so "oldest waiting" read
     * off that column resets on every failure — and a queue stuck in a retry
     * loop would report itself as perpetually fresh.
     */
    public function testWaitingSinceIsTheAgeOfTheMessageNotItsNextAttempt(): void
    {
        $this->enqueue('ingest', createdAt: '-2 hours', availableAt: '+5 minutes');

        $stats = $this->statsFor('ingest');

        self::assertGreaterThanOrEqual(7100, $stats['waitingSinceSeconds']);
        // Availability is in the future, and a negative age is not a thing.
        self::assertSame(0, $stats['oldestAgeSeconds']);
    }

    public function testRunningForIsHowLongTheWorkerHasHeldIt(): void
    {
        $this->enqueue('ingest', createdAt: '-30 minutes', deliveredAt: '-10 minutes');

        self::assertGreaterThanOrEqual(590, $this->statsFor('ingest')['runningForSeconds']);
    }

    /** What a worker is holding, named — the answer the panel leads with. */
    public function testRunningMessagesAreNamedAndDescribed(): void
    {
        $this->enqueue('ingest', createdAt: '-5 minutes', deliveredAt: '-1 minute', message: new SyncAccountMessage(42));

        $running = $this->monitor->runningMessages();

        self::assertCount(1, $running);
        self::assertSame('SyncAccountMessage', $running[0]['class']);
        self::assertSame('running', $running[0]['state']);
        self::assertStringContainsString('accountId: 42', $running[0]['summary']);
    }

    /**
     * Waiting and scheduled are different states: one is a queue that is behind,
     * the other is a message that asked to be run later. Showing a backed-off
     * retry as "waiting 3s" would make a healthy queue look stalled.
     */
    public function testAMessageBackingOffIsScheduledRatherThanWaiting(): void
    {
        $this->enqueue('ingest', createdAt: '-10 minutes', availableAt: '+10 minutes');
        $this->enqueue('ingest', createdAt: '-10 minutes');

        $states = array_column($this->monitor->waitingMessages(), 'state');

        sort($states);

        self::assertSame(['scheduled', 'waiting'], $states);
    }

    public function testRetryCountsComeFromTheEnvelope(): void
    {
        $envelope = (new Envelope(new SyncAccountMessage(7)))->with(new RedeliveryStamp(3));

        $this->enqueue('ingest', createdAt: '-1 minute', envelope: $envelope);

        self::assertSame(3, $this->monitor->waitingMessages()[0]['retries']);
    }

    /**
     * A body that no longer deserialises is the one message nobody can act on,
     * and dropping it from the list would hide the only thing worth seeing:
     * the queue cannot drain past it.
     */
    public function testAnUndecodableMessageIsListedRatherThanSkipped(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at)
             VALUES ('not a serialised envelope', '{}', 'ingest', NOW(), NOW())",
        );

        $waiting = $this->monitor->waitingMessages();

        self::assertCount(1, $waiting);
        self::assertSame('undecodable', $waiting[0]['class']);
        self::assertSame('', $waiting[0]['summary']);
    }

    /**
     * The failure transport has its own panel with its own actions. Listing it
     * here too would invite retrying a message from the one place that cannot.
     */
    public function testTheFailureQueueIsLeftToItsOwnPanel(): void
    {
        $this->enqueue('failed', createdAt: '-1 minute');
        $this->enqueue('ingest', createdAt: '-1 minute');

        $queues = array_column($this->monitor->waitingMessages(), 'queue');

        self::assertSame(['ingest'], $queues);
        // …but it is still counted, because the panel's totals are the depth of
        // the transport rather than of one panel.
        self::assertSame(1, $this->statsFor('failed')['pending']);
    }

    public function testThePageIsBoundedAndWalkable(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->enqueue('ingest', createdAt: sprintf('-%d minutes', 10 - $i));
        }

        self::assertCount(2, $this->monitor->waitingMessages(2));
        self::assertCount(3, $this->monitor->waitingMessages(10, 2));
        self::assertSame(5, $this->monitor->countWaiting());
    }

    /**
     * The filter searches the whole queue, not the page on screen — which means
     * matching the serialised body, since that is where the class name and the
     * payload live.
     */
    public function testTheFilterReachesTheClassAndThePayload(): void
    {
        $this->enqueue('ingest', createdAt: '-2 minutes', message: new SyncAccountMessage(42));
        $this->enqueue('export', createdAt: '-1 minute', message: new SyncAccountMessage(99));

        self::assertSame(2, $this->monitor->countWaiting('SyncAccountMessage'));
        self::assertSame(1, $this->monitor->countWaiting('export'));
        self::assertCount(1, $this->monitor->waitingMessages(filter: '99'));
        self::assertSame(0, $this->monitor->countWaiting('nothing matches this'));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** @return array{pending: int, running: int, oldestAgeSeconds: int|null, waitingSinceSeconds: int|null, runningForSeconds: int|null} */
    private function statsFor(string $queue): array
    {
        foreach ($this->monitor->queueStats() as $stats) {
            if ($stats['queue'] === $queue) {
                return $stats;
            }
        }

        self::fail(sprintf('No stats for queue "%s".', $queue));
    }

    /**
     * Written the way the doctrine transport writes: a PHP-serialised envelope
     * in `body`, which is what makes the decoding under test real.
     */
    private function enqueue(
        string $queue,
        string $createdAt,
        ?string $availableAt = null,
        ?string $deliveredAt = null,
        ?object $message = null,
        ?Envelope $envelope = null,
    ): void {
        $envelope ??= new Envelope($message ?? new SyncAccountMessage(1));
        $encoded    = (new PhpSerializer())->encode($envelope);

        $this->connection->insert('messenger_messages', [
            'body'         => $encoded['body'],
            'headers'      => json_encode($encoded['headers'] ?? [], JSON_THROW_ON_ERROR),
            'queue_name'   => $queue,
            'created_at'   => new DateTimeImmutable($createdAt),
            'available_at' => new DateTimeImmutable($availableAt ?? $createdAt),
            'delivered_at' => null === $deliveredAt ? null : new DateTimeImmutable($deliveredAt),
        ], [
            'created_at'   => 'datetime_immutable',
            'available_at' => 'datetime_immutable',
            'delivered_at' => 'datetime_immutable',
        ]);
    }
}
