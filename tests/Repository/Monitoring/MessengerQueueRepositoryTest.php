<?php

declare(strict_types=1);

namespace App\Tests\Repository\Monitoring;

use App\Repository\Monitoring\MessengerQueueRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Queue depth, which is what the healthcheck turns into "is anything consuming
 * this".
 *
 * The load-bearing part is `delivered_at IS NULL`. Counting every row instead
 * would make a healthy stack that has processed a large first sync look
 * permanently backed up — the messages are done, the rows linger — and the
 * threshold the healthcheck compares against would never be met again.
 */
final class MessengerQueueRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private MessengerQueueRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(MessengerQueueRepository::class);

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

    public function testOnlyUndeliveredMessagesCountAsPending(): void
    {
        $this->enqueue('sync', '-10 minutes');
        $this->enqueue('sync', '-5 minutes');
        $this->enqueue('sync', '-1 minute', deliveredAt: '-30 seconds');

        $stats = $this->repository->pendingByQueue();

        self::assertCount(1, $stats);
        self::assertSame('sync', $stats[0]['queue_name']);
        self::assertSame(2, (int) $stats[0]['pending']);
    }

    public function testEachQueueIsCountedSeparatelyAndOrderedByName(): void
    {
        $this->enqueue('sync', '-1 minute');
        $this->enqueue('async', '-1 minute');
        $this->enqueue('async', '-2 minutes');

        $stats = $this->repository->pendingByQueue();

        self::assertSame(['async', 'sync'], array_column($stats, 'queue_name'));
        self::assertSame([2, 1], array_map('intval', array_column($stats, 'pending')));
    }

    /**
     * The oldest waiting message is how long the backlog has been a backlog,
     * so it has to be the earliest availability and not the latest.
     */
    public function testTheOldestIsTheEarliestMessageStillWaiting(): void
    {
        $this->enqueue('sync', '-1 minute');
        $this->enqueue('sync', '-30 minutes');

        $stats = $this->repository->pendingByQueue();

        self::assertLessThanOrEqual(
            (new \DateTimeImmutable('-29 minutes'))->getTimestamp(),
            strtotime((string) $stats[0]['oldest']),
        );
    }

    public function testAnEmptyTransportReportsNothingRatherThanZero(): void
    {
        self::assertSame([], $this->repository->pendingByQueue());
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function enqueue(string $queue, string $availableAt, ?string $deliveredAt = null): void
    {
        $this->connection->executeStatement(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
             VALUES (:body, :headers, :queue, :createdAt, :availableAt, :deliveredAt)',
            [
                'body'        => '{}',
                'headers'     => '{}',
                'queue'       => $queue,
                'createdAt'   => (new \DateTimeImmutable($availableAt))->format('Y-m-d H:i:s'),
                'availableAt' => (new \DateTimeImmutable($availableAt))->format('Y-m-d H:i:s'),
                'deliveredAt' => null === $deliveredAt
                    ? null
                    : (new \DateTimeImmutable($deliveredAt))->format('Y-m-d H:i:s'),
            ],
        );
    }
}
