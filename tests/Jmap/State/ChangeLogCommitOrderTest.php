<?php

declare(strict_types=1);

namespace App\Tests\Jmap\State;

use App\Jmap\State\ChangeLog;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Migrations\Version\Version;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * State tokens follow commit order per account (Version20260923140100).
 *
 * The identity value used to be the token and was drawn at INSERT, so two
 * writers of one account could commit out of order and a client holding the
 * later token never saw the earlier row. What proves the fix is the lock: a
 * transaction that has written a change row for an account holds that
 * account's lock until it commits, so nobody else can draw a number for it
 * in between — and the number Doctrine hands back is the one stored.
 *
 * The migration's own statements are applied inside this test's transaction,
 * so the test runs against a database that has not been migrated yet and
 * leaves it exactly as it found it.
 */
final class ChangeLogCommitOrderTest extends KernelTestCase
{
    private const int LOCK_NAMESPACE = 1246576976;
    private const int ACCOUNT        = 2_000_000_101;
    private const int OTHER_ACCOUNT  = 2_000_000_102;

    private Connection $connection;
    private ?Connection $observer = null;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        // Through Doctrine Migrations' own loader: migrations are not on the
        // autoloader, and this is how the real run finds them.
        $migration = self::getContainer()->get('doctrine.migrations.dependency_factory')
            ->getMigrationRepository()
            ->getMigration(new Version('DoctrineMigrations\\Version20260923140100'))
            ->getMigration();

        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }

    protected function tearDown(): void
    {
        $this->observer?->close();

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAWriterHoldsItsAccountUntilCommitAndKeepsTheStoredNumber(): void
    {
        $container = self::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $container->get(StateManager::class)->recordCreated(self::ACCOUNT, JmapObjectType::Email, 'e-1');
        $em->flush();

        $row = $em->getRepository(ChangeLog::class)->findOneBy(['accountId' => self::ACCOUNT]);
        self::assertNotNull($row);

        $stored = (int) $this->connection->fetchOne(
            'SELECT sequence FROM jmap_change_log WHERE account_id = ?',
            [self::ACCOUNT],
        );

        self::assertSame($stored, $row->sequence, 'the entity carries the number the trigger stored');

        // A second session, as a concurrent writer would be.
        $this->observer = DriverManager::getConnection($this->connection->getParams());

        self::assertFalse(
            (bool) $this->observer->fetchOne(
                'SELECT pg_try_advisory_xact_lock(?, ?)',
                [self::LOCK_NAMESPACE, self::ACCOUNT],
            ),
            'another writer of this account must wait for the commit',
        );

        self::assertTrue(
            (bool) $this->observer->fetchOne(
                'SELECT pg_try_advisory_xact_lock(?, ?)',
                [self::LOCK_NAMESPACE, self::OTHER_ACCOUNT],
            ),
            'writers of other accounts are not held up',
        );
    }
}
