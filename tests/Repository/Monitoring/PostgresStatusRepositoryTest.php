<?php

declare(strict_types=1);

namespace App\Tests\Repository\Monitoring;

use App\Repository\Monitoring\PostgresStatusRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The catalogue reads behind /healthz and the admin performance board.
 *
 * The contract worth pinning is that none of these ever throws. They are read
 * by a page that must still render when the extension is missing, and by an
 * unauthenticated probe whose whole job is to answer rather than to fail — a
 * healthcheck that 500s because monitoring 500'd reports an outage that is not
 * happening.
 *
 * Deliberately not run inside a transaction: pg_stat_activity and
 * pg_stat_database describe the server, not this session's uncommitted work,
 * and nothing here writes.
 */
final class PostgresStatusRepositoryTest extends KernelTestCase
{
    private PostgresStatusRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->repository = self::getContainer()->get(PostgresStatusRepository::class);
    }

    public function testAReachableDatabaseAnswers(): void
    {
        self::assertTrue($this->repository->isReachable());
    }

    public function testTheHealthGaugesDescribeTheCurrentDatabase(): void
    {
        $stats = $this->repository->databaseStats();

        self::assertNotNull($stats);
        self::assertArrayHasKey('numbackends', $stats);
        self::assertArrayHasKey('blks_hit', $stats);
        self::assertGreaterThanOrEqual(1, (int) $stats['numbackends'], 'this test is itself a backend');
    }

    /**
     * The board is a picture of what OTHER connections are doing, so the
     * session asking must never appear in it — a monitoring page that listed
     * its own query would report a stuck backend on every refresh, and the
     * query it would name is the one drawing the page.
     */
    public function testTheAskingBackendIsNeverListedAsActive(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $ownPid     = (int) $connection->fetchOne('SELECT pg_backend_pid()');

        $pids = array_map(
            static fn (array $row): int => (int) $row['pid'],
            $this->repository->activeBackends(),
        );

        self::assertNotContains($ownPid, $pids);
    }

    /** Every key the dashboard reads has to be present on whatever comes back. */
    public function testActiveBackendsComeBackInTheShapeTheBoardReads(): void
    {
        $rows = $this->repository->activeBackends();

        foreach ($rows as $row) {
            self::assertArrayHasKey('pid', $row);
            self::assertArrayHasKey('state', $row);
            self::assertArrayHasKey('wait_event', $row);
            self::assertArrayHasKey('duration_seconds', $row);
            self::assertArrayHasKey('query', $row);
        }

        self::assertLessThanOrEqual(25, count($rows), 'the board is capped');
    }

    /**
     * Whether pg_stat_statements is installed is an operator's choice, so the
     * assertion is on the consequence rather than the answer: without it the
     * statement views must degrade to an empty set instead of raising.
     */
    public function testTheStatementViewsDegradeRatherThanFailWhenTheExtensionIsAbsent(): void
    {
        $statements = $this->repository->statementsSlowestByMean(5, 0.0);

        if (false === $this->repository->hasStatStatements()) {
            self::assertSame([], $statements);
            self::assertSame([], $this->repository->statementsHeaviestByTotal(5));

            return;
        }

        // With the extension present, every row has to carry the columns the
        // board reads — a version mismatch would otherwise surface as a
        // notice-storm on the page rather than as a failure here.
        foreach ($statements as $row) {
            self::assertArrayHasKey('query', $row);
            self::assertArrayHasKey('calls', $row);
            self::assertArrayHasKey('mean_exec_time', $row);
            self::assertArrayHasKey('hit_pct', $row);
        }

        self::assertLessThanOrEqual(5, count($statements));
    }

    /**
     * "What is using the disk" — an answer no entity can give about itself,
     * since it is about indexes and TOAST as much as about rows.
     */
    public function testTableSizesNameRealTablesLargestFirst(): void
    {
        $sizes = $this->repository->tableSizes(5);

        self::assertNotSame([], $sizes, 'the schema has tables');
        self::assertLessThanOrEqual(5, count($sizes));

        $bytes = array_map(static fn (array $row): int => (int) $row['bytes'], $sizes);
        $sorted = $bytes;
        rsort($sorted);

        self::assertSame($sorted, $bytes, 'largest first');
        self::assertContains('message', array_map(
            static fn (array $row): string => (string) $row['table_name'],
            $this->repository->tableSizes(100),
        ));
    }

    /** A monitoring read against a dead server is an empty answer, not an error. */
    public function testEveryReadDegradesOnAnUnusableConnection(): void
    {
        $repository = new PostgresStatusRepository($this->unreachableConnection());

        self::assertFalse($repository->isReachable());
        self::assertFalse($repository->hasStatStatements());
        self::assertSame([], $repository->activeBackends());
        self::assertNull($repository->databaseStats());
        self::assertSame([], $repository->statementsHeaviestByTotal(5));
        self::assertSame([], $repository->tableSizes(5));
        self::assertFalse($repository->resetStatStatements());
    }

    private function unreachableConnection(): Connection
    {
        return \Doctrine\DBAL\DriverManager::getConnection([
            'driver'   => 'pdo_pgsql',
            'host'     => '127.0.0.1',
            'port'     => 1,
            'user'     => 'nobody',
            'password' => 'nobody',
            'dbname'   => 'nothing',
        ]);
    }
}
