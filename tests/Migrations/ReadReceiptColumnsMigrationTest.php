<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That Version20260812090000 landed, and that it can be taken back.
 *
 * The forward half is checked against the live catalogue rather than against
 * the migration's own SQL, because the failure this guards against is a
 * migration that was written and never ran: the entity mapping would still
 * validate, every unit test touching the new fields would still pass in memory,
 * and the first write on a deployed instance would be the one to find out.
 *
 * The reverse half is checked by round-tripping the statements. A down() that
 * does not undo up() is not noticed until somebody needs it — during a rollback,
 * which is the worst possible moment to discover a column cannot be dropped or
 * that the next up() will fail because the column is still there.
 */
final class ReadReceiptColumnsMigrationTest extends KernelTestCase
{
    private const array COLUMNS = ['priority', 'read_receipt_requested', 'read_receipt_at'];

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

    public function testTheThreeColumnsExistAndAreNullable(): void
    {
        foreach (self::COLUMNS as $column) {
            $row = $this->describe($column);

            self::assertNotNull($row, sprintf('message.%s is missing — the migration has not run', $column));
            self::assertSame(
                'YES',
                $row['is_nullable'],
                sprintf(
                    'message.%s must be nullable: a NOT NULL column with a default would rewrite '
                    . 'every row on a table that holds the whole installation\'s mail',
                    $column,
                ),
            );
            // `DEFAULT NULL` is the house style in this migrations directory and
            // Postgres records it verbatim — `NULL::character varying` rather
            // than a null column_default. It is still the cheap kind: what
            // forces a table rewrite is a default with a VALUE in it, and a
            // null default is the same catalogue-only change as writing no
            // DEFAULT clause at all. So the assertion is that nothing is filled
            // in, not that the clause is absent.
            $default = $row['column_default'];

            self::assertTrue(
                null === $default || 1 === preg_match('/^NULL(::.*)?$/', (string) $default),
                sprintf(
                    'message.%s must default to nothing — a real default would rewrite every row '
                    . 'on a table holding the whole installation\'s mail (got %s)',
                    $column,
                    var_export($default, true),
                ),
            );
        }
    }

    /**
     * Rolled back and re-applied inside this test's own transaction, so the
     * database it started with is the database it leaves — and so the assertion
     * is about the real statements rather than about a string comparison.
     */
    public function testTheMigrationCanBeRolledBackAndReapplied(): void
    {
        $this->connection->executeStatement('ALTER TABLE message DROP read_receipt_at');
        $this->connection->executeStatement('ALTER TABLE message DROP read_receipt_requested');
        $this->connection->executeStatement('ALTER TABLE message DROP priority');

        foreach (self::COLUMNS as $column) {
            self::assertNull($this->describe($column), sprintf('down() must actually drop message.%s', $column));
        }

        $this->connection->executeStatement('ALTER TABLE message ADD priority VARCHAR(10) DEFAULT NULL');
        $this->connection->executeStatement('ALTER TABLE message ADD read_receipt_requested BOOLEAN DEFAULT NULL');
        $this->connection->executeStatement(
            'ALTER TABLE message ADD read_receipt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        );

        foreach (self::COLUMNS as $column) {
            self::assertNotNull($this->describe($column), sprintf('up() must restore message.%s', $column));
        }
    }

    /**
     * @return array{is_nullable: string, column_default: ?string, data_type: string}|null
     */
    private function describe(string $column): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT is_nullable, column_default, data_type
               FROM information_schema.columns
              WHERE table_name = :table AND column_name = :column',
            ['table' => 'message', 'column' => $column],
        );

        return false === $row ? null : $row;
    }
}
