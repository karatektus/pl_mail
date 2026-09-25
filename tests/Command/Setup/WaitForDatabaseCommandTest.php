<?php

declare(strict_types=1);

namespace App\Tests\Command\Setup;

use App\Command\Setup\WaitForDatabaseCommand;
use App\Repository\Monitoring\PostgresStatusRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception as PdoException;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Result;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The boot-time wait for the database, and when it is allowed to log.
 *
 * Never for a database that turns up late — that is the whole reason the
 * command exists, see its docblock — and once, with the reason, for one that
 * does not turn up at all.
 */
final class WaitForDatabaseCommandTest extends TestCase
{
    public function testADatabaseThatAnswersLateIsWaitedForWithoutLogging(): void
    {
        $log     = new TestHandler();
        $command = new CommandTester($this->command($this->answeringAfter(2), $log));

        self::assertSame(Command::SUCCESS, $command->execute([]));
        self::assertSame([], $log->getRecords());
        self::assertStringContainsString('58 attempts left', $command->getDisplay());
    }

    public function testADatabaseThatNeverAnswersIsLoggedOnceWithTheReason(): void
    {
        $log     = new TestHandler();
        $command = new CommandTester($this->command($this->unreachableConnection(), $log));

        self::assertSame(Command::FAILURE, $command->execute(['--attempts' => '3']));
        self::assertCount(1, $log->getRecords());
        self::assertTrue($log->hasCriticalThatContains('did not answer in 3 attempts'));
        self::assertInstanceOf(ConnectionException::class, $log->getRecords()[0]->context['exception']);
        self::assertStringContainsString('not up or not reachable', $command->getDisplay());
    }

    private function command(Connection $connection, TestHandler $log): WaitForDatabaseCommand
    {
        return new WaitForDatabaseCommand(
            new PostgresStatusRepository($connection),
            // As production words it, placeholders filled in.
            new Logger('test', [$log], [new PsrLogMessageProcessor()]),
            pauseMicroseconds: 0,
        );
    }

    /** Misses the way a database container that does not exist yet does, then answers. */
    private function answeringAfter(int $misses): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturnCallback(
            function () use (&$misses, $connection): Result {
                if ($misses-- > 0) {
                    throw new ConnectionException(PdoException::new(new \PDOException(
                        'SQLSTATE[08006] [7] could not translate host name "database" to address: Name or service not known',
                    )), null);
                }

                return new Result($this->createStub(DriverResult::class), $connection);
            },
        );

        return $connection;
    }

    private function unreachableConnection(): Connection
    {
        return DriverManager::getConnection([
            'driver'   => 'pdo_pgsql',
            'host'     => '127.0.0.1',
            'port'     => 1,
            'user'     => 'nobody',
            'password' => 'nobody',
            'dbname'   => 'nothing',
        ]);
    }
}
