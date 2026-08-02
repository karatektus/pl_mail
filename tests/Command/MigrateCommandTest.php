<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\Setup\MigrateCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The advisory lock that keeps four containers from migrating at once.
 *
 * What is NOT tested here, deliberately: four real migrates racing. PHPUnit is
 * one process against one database that is already at the latest version, so
 * "they collide" cannot be staged inside it without forking processes and
 * rolling the schema backwards underneath the suite — a test that breaks the
 * database it runs on when it fails. That case was reproduced and re-verified
 * by hand instead (four concurrent boots: three exited 7 with "column
 * 'timezone' of relation 'user' already exists" before the fix, all four exited
 * 0 after).
 *
 * What is tested is the part that makes that outcome possible and that could
 * silently stop working: the lock is genuinely exclusive across sessions, a
 * container that cannot get it refuses to migrate rather than pressing on, and
 * the lock is handed back afterwards. If any of those three regress, boot goes
 * back to being a race.
 */
final class MigrateCommandTest extends KernelTestCase
{
    private Connection $connection;
    private CommandTester $command;

    /** The session standing in for another container mid-migration. */
    private ?Connection $otherContainer = null;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->command    = new CommandTester(
            new Application(self::$kernel)->find('app:db:migrate'),
        );
    }

    protected function tearDown(): void
    {
        // Session-scoped, so closing is enough — but an assertion that failed
        // mid-test would otherwise leave the lock held for as long as the
        // connection lived, and every later test in the process would wait it
        // out.
        $this->otherContainer?->close();
        $this->otherContainer = null;

        parent::tearDown();
    }

    public function testWaitingContainerRefusesToMigrateWhenTheLockNeverFrees(): void
    {
        $this->otherContainer = $this->openSecondSession();
        $this->otherContainer->executeQuery('SELECT pg_advisory_lock(?)', [$this->lockKey()]);

        // Short, because the point is the timeout branch and not the wait.
        $exit = $this->command->execute(['--lock-timeout' => '1']);

        self::assertSame(Command::FAILURE, $exit);

        $display = $this->command->getDisplay();

        // The message has to name what it was waiting for: a container that
        // dies on boot with a bare non-zero exit tells an operator nothing.
        self::assertStringContainsString('Timed out', $display);
        self::assertStringContainsString('migrations', $display);

        // The whole point of bounding the wait is that it stays a refusal.
        // Giving up and migrating anyway would be the original race with extra
        // steps.
        self::assertStringNotContainsString('Migrating', $display);
    }

    public function testTheLockIsExclusiveBetweenSessions(): void
    {
        $this->otherContainer = $this->openSecondSession();

        self::assertSame(
            1,
            (int) $this->otherContainer->fetchOne('SELECT pg_try_advisory_lock(?)::int', [$this->lockKey()]),
            'A free lock has to be takeable, or this test proves nothing below.',
        );

        // Same key, different session: this is the mutual exclusion the boot
        // sequence rests on. Postgres advisory locks are re-entrant within a
        // session, so testing it from one connection would always pass.
        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT pg_try_advisory_lock(?)::int', [$this->lockKey()]),
        );

        $this->connection->executeQuery('SELECT pg_advisory_unlock(?)', [$this->lockKey()]);
    }

    public function testTheLockIsHandedBackWhenTheCommandFinishes(): void
    {
        // Nothing is pending — the suite runs on a migrated database — so this
        // is the normal case: the container that has nothing to do still takes
        // the lock, and must not sit on it.
        self::assertSame(Command::SUCCESS, $this->command->execute([]));

        $this->otherContainer = $this->openSecondSession();

        self::assertSame(
            1,
            (int) $this->otherContainer->fetchOne('SELECT pg_try_advisory_lock(?)::int', [$this->lockKey()]),
            'The lock was still held after the command exited; the next container would wait for nothing.',
        );

        $this->otherContainer->executeQuery('SELECT pg_advisory_unlock(?)', [$this->lockKey()]);
    }

    /**
     * A second connection to the same database, which is what makes these
     * assertions mean anything: the lock is scoped to a session, and the
     * container this is standing in for has its own.
     */
    private function openSecondSession(): Connection
    {
        return DriverManager::getConnection($this->connection->getParams());
    }

    /**
     * Read off the command rather than copied, so a change to the key cannot
     * leave a green test asserting against the old one.
     */
    private function lockKey(): int
    {
        return (int) new \ReflectionClassConstant(MigrateCommand::class, 'LOCK_KEY')->getValue();
    }
}
