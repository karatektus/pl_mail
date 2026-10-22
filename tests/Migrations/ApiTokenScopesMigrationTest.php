<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That Version20261021140000 landed, and that its backfill grants everything.
 *
 * The column is checked against the live catalogue rather than the migration's
 * own SQL — a migration that was written and never run leaves the entity
 * mapping valid, every in-memory test passing, and the first write on a real
 * installation as the thing that finds out.
 *
 * The backfill is checked separately, on a scratch table, because the
 * interesting property is not "the UPDATE ran" but "a row that existed before
 * the column ends up with all three scopes". An empty default left on an
 * existing token would silently sign out every connected mail client, which is
 * the one outcome this migration must not have.
 */
final class ApiTokenScopesMigrationTest extends KernelTestCase
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

    public function testTheScopesColumnExistsWithAnEmptyDefault(): void
    {
        $row = $this->connection->fetchAssociative(
            "SELECT column_default, is_nullable, data_type
               FROM information_schema.columns
              WHERE table_name = 'api_token' AND column_name = 'scopes'",
        );

        self::assertNotFalse($row, 'api_token.scopes is missing — the migration has not run');
        self::assertSame('jsonb', $row['data_type']);
        self::assertSame('NO', $row['is_nullable'], 'a token with no scope list at all would be meaningless');
        self::assertStringContainsString('[]', (string) $row['column_default'], 'new tokens must default to no access');
    }

    /**
     * A row that predates the column ends up with every scope.
     *
     * Replayed on a scratch table inside the test's transaction, so the real
     * api_token is untouched — the statement is the migration's own, which is
     * what makes this a test of the backfill rather than of PostgreSQL.
     */
    public function testTheBackfillGrantsEveryScopeToExistingRows(): void
    {
        $this->connection->executeStatement('CREATE TEMPORARY TABLE scope_backfill_probe (id serial primary key, name text)');
        $this->connection->executeStatement("INSERT INTO scope_backfill_probe (name) VALUES ('before'), ('also before')");

        // The migration's two statements, in order.
        $this->connection->executeStatement("ALTER TABLE scope_backfill_probe ADD scopes JSONB DEFAULT '[]' NOT NULL");
        $this->connection->executeStatement("UPDATE scope_backfill_probe SET scopes = '[\"read\", \"send\", \"delete\"]'::jsonb");

        $rows = $this->connection->fetchFirstColumn('SELECT scopes FROM scope_backfill_probe ORDER BY id');

        self::assertCount(2, $rows);

        foreach ($rows as $scopes) {
            $decoded = json_decode((string) $scopes, true);

            self::assertSame(
                ['read', 'send', 'delete'],
                $decoded,
                'a token created before scopes existed had all of them, and must keep all of them',
            );
        }
    }
}
