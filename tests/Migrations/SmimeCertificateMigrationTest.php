<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Tests\Command\MailFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That Version20261017090000 landed — smime_certificate and
 * contact_certificate, with the partial index that holds "one certificate in
 * use per address" — and that its down() and up() take both out and put them
 * back.
 *
 * As ContactKeyMigrationTest does it: the index by behaviour, the cascade by
 * deleting the person and the user key, the round trip with the migration's
 * own statements inside this test's transaction.
 */
final class SmimeCertificateMigrationTest extends KernelTestCase
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

    public function testContactCertificatesHoldOneCurrentPerAddress(): void
    {
        self::assertTrue($this->tableExists('contact_certificate'), 'contact_certificate is missing — the migration has not run');

        $userId = $this->seedUser();
        $this->insertContactCertificate($userId, 'a', 'changed');
        $this->insertContactCertificate($userId, 'b', 'current');

        $this->connection->executeStatement('SAVEPOINT second_current');

        try {
            $this->insertContactCertificate($userId, 'c', 'current');
            self::fail('a second Current certificate for one address was accepted');
        } catch (UniqueConstraintViolationException) {
            $this->connection->executeStatement('ROLLBACK TO SAVEPOINT second_current');
        }

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM contact_certificate WHERE usr_id = ?', [$userId]));
    }

    /** The wrapped key is worth nothing without its user key, and goes with it. */
    public function testAnOwnCertificateGoesWithItsUserKeyAndWithThePerson(): void
    {
        $userId    = $this->seedUser();
        $userKeyId = $this->insertUserKey($userId);
        $this->insertOwnCertificate($userId, $userKeyId, 'a');

        $this->connection->executeStatement('DELETE FROM user_key WHERE id = ?', [$userKeyId]);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM smime_certificate WHERE usr_id = ?', [$userId]));

        $this->insertOwnCertificate($userId, $this->insertUserKey($userId), 'b');
        $this->insertContactCertificate($userId, 'c', 'current');
        $this->connection->executeStatement('DELETE FROM "user" WHERE id = ?', [$userId]);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM smime_certificate WHERE usr_id = ?', [$userId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM contact_certificate WHERE usr_id = ?', [$userId]));
    }

    public function testTheMigrationCanBeRolledBackAndReapplied(): void
    {
        $this->migrate('down');
        self::assertFalse($this->tableExists('smime_certificate'), 'down() must drop smime_certificate');
        self::assertFalse($this->tableExists('contact_certificate'), 'down() must drop contact_certificate');

        $this->migrate('up');
        self::assertTrue($this->tableExists('smime_certificate'), 'up() must create smime_certificate again');
        self::assertTrue($this->tableExists('contact_certificate'), 'up() must create contact_certificate again');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function migrate(string $direction): void
    {
        $file = self::getContainer()->getParameter('kernel.project_dir') . '/migrations/Version20261017090000.php';
        require_once $file;

        $class = 'DoctrineMigrations\\' . pathinfo($file, PATHINFO_FILENAME);
        /** @var AbstractMigration $migration */
        $migration = new $class($this->connection, new NullLogger());
        $migration->{$direction}(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }

    private function tableExists(string $table): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM information_schema.tables WHERE table_name = ?', [$table]);
    }

    private function seedUser(): int
    {
        return (int) MailFixtures::user(self::getContainer()->get(EntityManagerInterface::class), 'migration')->id;
    }

    private function insertUserKey(int $userId): int
    {
        return (int) $this->connection->fetchOne(
            "INSERT INTO user_key (usr_id, wrapped, status, created_at, updated_at) VALUES (?, 'v1.x.y', 'active', NOW(), NOW()) RETURNING id",
            [$userId],
        );
    }

    private function insertOwnCertificate(int $userId, int $userKeyId, string $seed): void
    {
        $this->connection->executeStatement(
            "INSERT INTO smime_certificate (usr_id, user_key_id, fingerprint, certificate, chain, private_key, addresses, subject, issuer, not_before, not_after, status, created_at, updated_at)
             VALUES (?, ?, ?, 'cert', '', 'v1.x.y', '[\"anna@example.test\"]', 'Anna', 'CA', NOW(), NOW() + INTERVAL '1 year', 'active', NOW(), NOW())",
            [$userId, $userKeyId, str_repeat($seed, 64)],
        );
    }

    private function insertContactCertificate(int $userId, string $seed, string $status): void
    {
        $this->connection->executeStatement(
            "INSERT INTO contact_certificate (usr_id, email, fingerprint, certificate, verified, status, subject, issuer, not_before, not_after, first_seen_at, last_seen_at, created_at, updated_at)
             VALUES (?, 'ben@example.test', ?, 'cert', false, ?, 'Ben', 'CA', NOW(), NOW() + INTERVAL '1 year', NOW(), NOW(), NOW(), NOW())",
            [$userId, str_repeat($seed, 64), $status],
        );
    }
}
