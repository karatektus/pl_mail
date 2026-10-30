<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That the calendar columns whose length the remote decides are unbounded (#443),
 * in the columns and in their indexes.
 *
 * Against the live catalogue, because a migration that never ran leaves the
 * mapping valid and every in-memory test passing.
 */
final class CalendarRemoteIdentifierColumnsMigrationTest extends KernelTestCase
{
    #[DataProvider('columns')]
    public function testColumnIsText(string $table, string $column): void
    {
        self::bootKernel();

        $type = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$table, $column],
        );

        self::assertSame('text', $type, sprintf('%s.%s', $table, $column));
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function columns(): iterable
    {
        yield 'event uid' => ['calendar_event', 'uid'];
        yield 'event etag' => ['calendar_event', 'remote_etag'];
        yield 'change log uid' => ['calendar_change_log', 'event_uid'];
        yield 'calendar remote id' => ['calendar', 'remote_id'];
        yield 'push channel' => ['calendar', 'push_channel_id'];
        yield 'push resource' => ['calendar', 'push_resource_id'];
        yield 'source link dedup key' => ['event_source_link', 'dedup_key'];
    }

    /**
     * That none of them is left in a btree, which would refuse a long value.
     */
    #[DataProvider('indexes')]
    public function testIndexCanTakeAnyLength(string $index, string $expected): void
    {
        self::bootKernel();

        $definition = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT indexdef FROM pg_indexes WHERE indexname = ?',
            [$index],
        );

        self::assertStringContainsString($expected, (string) $definition);
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function indexes(): iterable
    {
        yield 'uid' => ['idx_calendar_event_uid', 'USING hash (uid)'];
        yield 'remote id' => ['idx_calendar_event_remote', 'USING hash (remote_id)'];
        yield 'uid per calendar' => ['uniq_calendar_event_calendar_uid', '(calendar_id, uid_hash)'];
        yield 'calendar per account' => ['uniq_calendar_account_remote', '(account_id, remote_id_hash)'];
        yield 'calendar per integration' => ['uniq_calendar_integration_remote', '(integration_id, remote_id_hash)'];
        yield 'one calendar per channel' => ['uniq_calendar_push_channel_id', '(push_channel_id_hash)'];
        yield 'webhook lookup' => ['idx_calendar_push_channel', 'USING hash (push_channel_id)'];
        yield 'dedup key' => ['idx_event_source_link_dedup', 'USING hash (dedup_key)'];
    }

    /**
     * Random, because a repetitive value compresses and would fit a btree anyway.
     * Uniqueness has to survive the move to hashes.
     */
    public function testACalendarTakesIdentifiersOfAnyLengthAndStillRefusesADuplicateChannel(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        $connection->beginTransaction();

        try {
            $userId = (int) $connection->fetchOne(
                'INSERT INTO "user" (email, name_first, name_last, password, roles, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, now(), now()) RETURNING id',
                ['long-id-' . bin2hex(random_bytes(4)) . '@plmail.test', 'Probe', 'Fixture', 'x', '[]'],
            );
            $insert = 'INSERT INTO calendar (name, usr_id, remote_id, push_channel_id, push_resource_id, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, now(), now())';
            $channel = bin2hex(random_bytes(50000));

            $connection->executeStatement($insert, [
                'Probe',
                $userId,
                bin2hex(random_bytes(50000)),
                $channel,
                bin2hex(random_bytes(50000)),
            ]);

            self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM calendar WHERE push_channel_id = ?', [$channel]));

            $this->expectException(UniqueConstraintViolationException::class);
            $connection->executeStatement($insert, ['Probe', $userId, null, $channel, null]);
        } finally {
            $connection->rollBack();
        }
    }
}
