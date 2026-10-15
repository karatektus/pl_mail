<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Tests\Command\MailFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That Version20261014190000 landed — with the partial index that holds
 * "one key in use per address" — and that its own down() and up() take it
 * back and put it there again.
 *
 * The index is checked by behaviour rather than by name: a second Current
 * key for an address must be refused by the database, because that is the
 * guard ContactKeys leans on when two workers collect keys at once. The
 * round trip runs the migration's real statements, inside this test's
 * transaction, so the database it started with is the one it leaves.
 */
final class ContactKeyMigrationTest extends KernelTestCase
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

    public function testTheTableExistsAndHoldsOneCurrentKeyPerAddress(): void
    {
        self::assertTrue($this->tableExists(), 'contact_key is missing — the migration has not run');

        $userId = $this->seedUser();
        $this->insertKey($userId, 'a', 'changed');
        $this->insertKey($userId, 'b', 'changed');
        $this->insertKey($userId, 'c', 'current');

        $this->connection->executeStatement('SAVEPOINT second_current');

        try {
            $this->insertKey($userId, 'd', 'current');
            self::fail('a second Current key for one address was accepted');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $this->connection->executeStatement('ROLLBACK TO SAVEPOINT second_current');
        }

        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM contact_key WHERE usr_id = ?', [$userId]));
    }

    public function testDeletingThePersonDeletesTheirContactKeys(): void
    {
        $userId = $this->seedUser();
        $this->insertKey($userId, 'a', 'current');

        $this->connection->executeStatement('DELETE FROM "user" WHERE id = ?', [$userId]);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM contact_key WHERE usr_id = ?', [$userId]));
    }

    public function testTheMigrationCanBeRolledBackAndReapplied(): void
    {
        $this->migrate('down');
        self::assertFalse($this->tableExists(), 'down() must drop contact_key');

        $this->migrate('up');
        self::assertTrue($this->tableExists(), 'up() must create contact_key again');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function migrate(string $direction): void
    {
        // Migrations are not autoloaded; the runner loads them by path, and
        // so does this. The class name comes from the file for the same
        // reason: static analysis does not see the migrations directory.
        $file = self::getContainer()->getParameter('kernel.project_dir') . '/migrations/Version20261014190000.php';
        require_once $file;

        $class = 'DoctrineMigrations\\' . pathinfo($file, PATHINFO_FILENAME);
        /** @var AbstractMigration $migration */
        $migration = new $class($this->connection, new NullLogger());
        $migration->{$direction}(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }

    private function tableExists(): bool
    {
        return false !== $this->connection->fetchOne("SELECT 1 FROM information_schema.tables WHERE table_name = 'contact_key'");
    }

    private function seedUser(): int
    {
        return (int) MailFixtures::user(self::getContainer()->get(EntityManagerInterface::class), 'migration')->id;
    }

    private function insertKey(int $userId, string $seed, string $status): void
    {
        $this->connection->executeStatement(
            "INSERT INTO contact_key (usr_id, email, fingerprint, public_key, origin, status, first_seen_at, last_seen_at, created_at, updated_at)
             VALUES (?, 'anna@example.test', ?, 'key', 'manual', ?, NOW(), NOW(), NOW(), NOW())",
            [$userId, str_repeat($seed, 40), $status],
        );
    }
}
