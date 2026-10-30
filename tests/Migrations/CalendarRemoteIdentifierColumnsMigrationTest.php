<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
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
        yield 'uid per calendar' => ['uniq_calendar_event_calendar_uid', 'md5(uid)'];
        yield 'dedup key' => ['idx_event_source_link_dedup', 'USING hash (dedup_key)'];
    }
}
