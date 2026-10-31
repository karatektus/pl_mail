<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Repository\Maintenance\DataResetRepository;
use App\Repository\Monitoring\PostgresStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That the entity mapping and the migrated database describe the same schema, as
 * doctrine:schema:validate asks.
 */
final class SchemaInSyncTest extends KernelTestCase
{
    public function testTheDiffIsEmpty(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);

        // The ledger is the migrations bundle's own: it hides it from the console
        // commands, and only from those, because it has to read the table itself.
        self::assertSame([], array_values(array_diff(
            new SchemaTool($em)->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()),
            ['DROP TABLE doctrine_migration_versions'],
        )));
    }

    /**
     * schema_filter hides these from the schema manager, and a data reset that
     * lost sight of them would keep message text in their rows.
     */
    #[DataProvider('rawSqlTables')]
    public function testARawSqlTableIsStillSeenWhereEveryTableHasToBe(string $table): void
    {
        self::bootKernel();

        $container = self::getContainer();

        self::assertContains($table, $container->get(DataResetRepository::class)->existingTables());
        self::assertTrue($container->get(PostgresStatusRepository::class)->hasTable($table));
    }

    public function testAReservedTableNameComesBackQuotedForTruncate(): void
    {
        self::bootKernel();

        self::assertContains('"user"', self::getContainer()->get(DataResetRepository::class)->existingTables());
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function rawSqlTables(): iterable
    {
        yield 'ai_backfill_state' => ['ai_backfill_state'];
        yield 'ai_call_metric' => ['ai_call_metric'];
        yield 'message_embedding' => ['message_embedding'];
        yield 'thread_summary' => ['thread_summary'];
    }
}
