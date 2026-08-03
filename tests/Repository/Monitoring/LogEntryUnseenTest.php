<?php

declare(strict_types=1);

namespace App\Tests\Repository\Monitoring;

use App\Entity\User\User;
use App\Repository\Monitoring\LogEntryRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the user menu's outline is drawn from.
 *
 * The load-bearing part is the boundary: entries older than the mark must not
 * count, or the outline never clears and stops meaning anything. The worst
 * level decides the colour, so it has to be the maximum and not the newest.
 */
final class LogEntryUnseenTest extends KernelTestCase
{
    private Connection $connection;
    private LogEntryRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(LogEntryRepository::class);

        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM log_entry');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testOnlyEntriesAfterTheMarkAreUnseen(): void
    {
        $this->log(400, '-2 hours');
        $this->log(300, '-1 minute');

        $unseen = $this->repository->unseenSince(new DateTimeImmutable('-30 minutes'), 300);

        self::assertSame(1, $unseen['count']);
        self::assertSame(300, $unseen['level']);
    }

    /** No mark at all means nobody has looked, so everything counts. */
    public function testNeverLookedCountsEverythingAndReportsTheWorst(): void
    {
        $this->log(300, '-2 hours');
        $this->log(500, '-1 hour');
        $this->log(300, '-1 minute');

        $unseen = $this->repository->unseenSince(null, 300);

        self::assertSame(3, $unseen['count']);
        self::assertSame(500, $unseen['level']);
    }

    /** Nothing above the threshold is not an outline of no colour — it is none. */
    public function testQuietLogReportsNoLevel(): void
    {
        $this->log(200, '-1 minute');

        $unseen = $this->repository->unseenSince(null, 300);

        self::assertSame(0, $unseen['count']);
        self::assertNull($unseen['level']);
    }

    /** Nobody has looked yet, and the bag says so rather than guessing a date. */
    public function testAFreshUserHasNoSeenMark(): void
    {
        self::assertNull((new User())->logsSeenAt);
    }

    /**
     * The mark round-trips through the settings bag, where it has no column of
     * its own — stored as a string and read back as an instant.
     *
     * Deliberately not asserting the initial null here too: the property is a
     * hook over the bag, and static analysis narrows it to null for the rest of
     * the method the moment a test says it is.
     */
    public function testTheSeenMarkSurvivesTheSettingsBag(): void
    {
        $user = new User();
        $seen = new DateTimeImmutable('2026-08-03 12:00:00');

        $user->logsSeenAt = $seen;

        self::assertSame($seen->getTimestamp(), $user->logsSeenAt?->getTimestamp());
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function log(int $level, string $createdAt): void
    {
        $this->connection->executeStatement(
            'INSERT INTO log_entry (channel, level, level_name, message, context, created_at, updated_at)
             VALUES (:channel, :level, :levelName, :message, :context, :createdAt, :createdAt)',
            [
                'channel'   => 'app',
                'level'     => $level,
                'levelName' => 'test',
                'message'   => 'test entry',
                'context'   => '[]',
                'createdAt' => (new DateTimeImmutable($createdAt))->format('Y-m-d H:i:s'),
            ],
        );
    }
}
