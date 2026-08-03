<?php

declare(strict_types=1);

namespace App\Tests\Repository\Monitoring;

use App\Entity\Monitoring\ProcessHeartbeat;
use App\Repository\Monitoring\ProcessHeartbeatRepository;
use App\Service\Monitoring\ProcessHeartbeatService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Liveness, which is only useful if it can be wrong in exactly one direction.
 *
 * A beat that inserted a second row per emission would grow the table by a row
 * every thirty seconds per worker and make the dashboard count one process as
 * many. A reaper that deleted rows it should have kept would report healthy
 * workers as dead. Both are silent, and both are what these pin.
 *
 * The upsert is also the only form that survives two instances of one worker
 * beating in the same instant, which is why it is ON CONFLICT rather than a
 * read followed by a write.
 */
final class ProcessHeartbeatRepositoryTest extends KernelTestCase
{
    private const string TYPE = 'test-worker';

    private Connection $connection;
    private ProcessHeartbeatRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(ProcessHeartbeatRepository::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testBeatingTwiceLeavesOneRow(): void
    {
        $this->repository->upsertBeat(self::TYPE, 'alpha', 111, ['round' => 1]);
        $this->repository->upsertBeat(self::TYPE, 'alpha', 222, ['round' => 2]);

        self::assertSame(1, $this->rowsOfType());
    }

    /** The point of beating again is that the newer facts win. */
    public function testASecondBeatReplacesWhatTheFirstRecorded(): void
    {
        $this->repository->upsertBeat(self::TYPE, 'alpha', 111, ['round' => 1]);
        $this->repository->upsertBeat(self::TYPE, 'alpha', 222, ['round' => 2]);

        $row = $this->connection->fetchAssociative(
            'SELECT pid, meta FROM process_heartbeat WHERE type = :type AND beat_key = :key',
            ['type' => self::TYPE, 'key' => 'alpha'],
        );

        self::assertNotFalse($row);
        self::assertSame(222, (int) $row['pid']);
        self::assertSame(['round' => 2], json_decode((string) $row['meta'], true));
    }

    public function testTwoKeysOfOneTypeAreTwoProcesses(): void
    {
        $this->repository->upsertBeat(self::TYPE, 'alpha', 111, null);
        $this->repository->upsertBeat(self::TYPE, 'beta', 222, null);

        self::assertSame(2, $this->rowsOfType());
    }

    // ── reconciliation ───────────────────────────────────────────────────────

    public function testOrphanReapingKeepsTheKeysThatAreStillAlive(): void
    {
        $this->repository->upsertBeat(self::TYPE, 'alpha', 111, null);
        $this->repository->upsertBeat(self::TYPE, 'beta', 222, null);
        $this->repository->upsertBeat(self::TYPE, 'gamma', 333, null);

        $reaped = $this->repository->deleteOrphans(self::TYPE, ['alpha', 'gamma']);

        self::assertSame(1, $reaped);
        self::assertSame(['alpha', 'gamma'], $this->keysOfType());
    }

    /**
     * An empty live set is a supervisor saying "nothing of mine is running",
     * which is different from saying nothing — a NOT IN () would match no rows
     * and quietly leave every stale entry standing.
     */
    public function testAnEmptyLiveSetReapsEveryKeyOfThatType(): void
    {
        $this->repository->upsertBeat(self::TYPE, 'alpha', 111, null);
        $this->repository->upsertBeat(self::TYPE, 'beta', 222, null);

        self::assertSame(2, $this->repository->deleteOrphans(self::TYPE, []));
        self::assertSame(0, $this->rowsOfType());
    }

    public function testOrphanReapingNeverTouchesAnotherProcessType(): void
    {
        $this->repository->upsertBeat(self::TYPE, 'alpha', 111, null);
        $this->repository->upsertBeat(ProcessHeartbeatService::TYPE_IMAP_IDLE, 'alpha', 222, null);

        $this->repository->deleteOrphans(self::TYPE, []);

        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM process_heartbeat WHERE type = :type',
            ['type' => ProcessHeartbeatService::TYPE_IMAP_IDLE],
        ));
    }

    // ── staleness ────────────────────────────────────────────────────────────

    public function testOnlyBeatsPastTheThresholdAreReaped(): void
    {
        $this->repository->upsertBeat(self::TYPE, 'fresh', 111, null);
        $this->repository->upsertBeat(self::TYPE, 'stale', 222, null);
        $this->ageBeat('stale', 600);

        self::assertSame(1, $this->repository->deleteStalerThan(self::TYPE, 300));
        self::assertSame(['fresh'], $this->keysOfType());
    }

    public function testTheUnknownTypeSweepSpecificallySkipsTheKnownOnes(): void
    {
        $this->repository->upsertBeat(self::TYPE, 'unknown', 111, null);
        $this->repository->upsertBeat(ProcessHeartbeatService::TYPE_IMAP_IDLE, 'known', 222, null);
        $this->ageBeat('unknown', 600);
        $this->ageBeat('known', 600);

        $reaped = $this->repository->deleteStalerThanForUnknownTypes(
            [ProcessHeartbeatService::TYPE_IMAP_IDLE],
            300,
        );

        self::assertSame(1, $reaped, 'the declared type is left to its own threshold');
        self::assertSame(0, $this->rowsOfType());
    }

    public function testTheDashboardListingIsOrderedByTypeThenKey(): void
    {
        $this->repository->upsertBeat(self::TYPE, 'beta', 111, null);
        $this->repository->upsertBeat(self::TYPE, 'alpha', 222, null);

        $listed = array_values(array_filter(
            $this->repository->findAllOrdered(),
            static fn (ProcessHeartbeat $beat): bool => self::TYPE === $beat->type,
        ));

        self::assertSame(
            ['alpha', 'beta'],
            array_map(static fn (ProcessHeartbeat $beat): string => (string) $beat->key, $listed),
        );
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function ageBeat(string $key, int $seconds): void
    {
        $this->connection->executeStatement(
            'UPDATE process_heartbeat
                SET last_beat_at = NOW() - (:seconds * INTERVAL \'1 second\')
              WHERE beat_key = :key',
            ['seconds' => $seconds, 'key' => $key],
        );
    }

    private function rowsOfType(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM process_heartbeat WHERE type = :type',
            ['type' => self::TYPE],
        );
    }

    /**
     * @return list<string>
     */
    private function keysOfType(): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT beat_key FROM process_heartbeat WHERE type = :type ORDER BY beat_key',
            ['type' => self::TYPE],
        ));
    }
}
