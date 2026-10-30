<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That the calendar columns whose length the remote decides are unbounded (#443).
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
    }
}
