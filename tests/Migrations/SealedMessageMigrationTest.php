<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That Version20261015090000 landed — sealed drafts, sealed attachments and
 * the seal of a message built in the browser — with defaults that say what
 * every existing row is, and that its own down() and up() take it back and
 * put it there again, inside this test's transaction.
 */
final class SealedMessageMigrationTest extends KernelTestCase
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

    public function testTheColumnsExistWithDefaultsForWhatWasThereBefore(): void
    {
        self::assertSame(['false', null, 'false'], [
            $this->defaultOf('message', 'sealed_draft'),
            $this->defaultOf('message', 'outgoing_seal'),
            $this->defaultOf('message_part', 'sealed'),
        ]);
    }

    public function testTheMigrationCanBeRolledBackAndReapplied(): void
    {
        $this->migrate('down');
        self::assertFalse($this->columnExists('message', 'sealed_draft'), 'down() must drop message.sealed_draft');
        self::assertFalse($this->columnExists('message_part', 'sealed'), 'down() must drop message_part.sealed');

        $this->migrate('up');
        self::assertTrue($this->columnExists('message', 'outgoing_seal'), 'up() must add message.outgoing_seal again');
    }

    private function migrate(string $direction): void
    {
        // Migrations are not autoloaded; the runner loads them by path, and
        // so does this — see ContactKeyMigrationTest.
        $file = self::getContainer()->getParameter('kernel.project_dir') . '/migrations/Version20261015090000.php';
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
