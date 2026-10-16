<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That Version20261016190000 landed — where an encrypted message's decrypted
 * body came from — with `none` for every existing row, and that its own
 * down() and up() take it back and put it there again, inside this test's
 * transaction.
 */
final class PlaintextSourceMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheColumnExistsWithNoneForWhatWasThereBefore(): void
    {
        self::assertSame("'none'::character varying", $this->defaultOf('message', 'plaintext_source'));
    }

    public function testTheMigrationCanBeRolledBackAndReapplied(): void
    {
        $this->migrate('down');
        self::assertFalse($this->columnExists('message', 'plaintext_source'), 'down() must drop message.plaintext_source');

        $this->migrate('up');
        self::assertTrue($this->columnExists('message', 'plaintext_source'), 'up() must add it again');
    }

    private function migrate(string $direction): void
    {
        // Migrations are not autoloaded; the runner loads them by path, and
        // so does this — see ContactKeyMigrationTest.
        $file = self::getContainer()->getParameter('kernel.project_dir') . '/migrations/Version20261016190000.php';
        require_once $file;

        $class = 'DoctrineMigrations\\' . pathinfo($file, PATHINFO_FILENAME);
        /** @var AbstractMigration $migration */
        $migration = new $class($this->connection, new NullLogger());
        $migration->{$direction}(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$table, $column],
        );
    }

    private function defaultOf(string $table, string $column): ?string
    {
        $default = $this->connection->fetchOne(
            'SELECT column_default FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$table, $column],
        );

        return false === $default ? 'missing' : $default;
    }
}
