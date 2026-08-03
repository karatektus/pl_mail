<?php

declare(strict_types=1);

namespace App\Tests\Repository\Monitoring;

use App\Entity\Monitoring\LogEntry;
use App\Repository\Monitoring\LogEntryRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The two log reads the Gmail push panel is built on.
 *
 * Both exist to answer "did the push path work", and both are only useful if
 * they are about the newest thing that happened: a panel that showed the
 * *first* failure of a misconfiguration fixed months ago would report an outage
 * that is over, and one that counted the wrong messages would report none at
 * all. So the assertions are about recency and about the exact-match set.
 */
final class LogEntryRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private LogEntryRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
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

    public function testEachMessageIsCountedOnceWithItsLatestOccurrence(): void
    {
        $this->log('GmailPush: unparseable envelope', 400, '2026-01-01 09:00');
        $this->log('GmailPush: unparseable envelope', 400, '2026-03-01 09:00');
        $this->log('GmailPush: envelope carried no data', 400, '2026-02-01 09:00');

        $rows = $this->repository->countsByMessage([
            'GmailPush: unparseable envelope',
            'GmailPush: envelope carried no data',
        ]);

        $byMessage = [];

        foreach ($rows as $row) {
            $byMessage[(string) $row['message']] = $row;
        }

        self::assertSame(2, (int) $byMessage['GmailPush: unparseable envelope']['hits']);
        self::assertSame(1, (int) $byMessage['GmailPush: envelope carried no data']['hits']);
        self::assertStringStartsWith(
            '2026-03-01',
            (string) $byMessage['GmailPush: unparseable envelope']['last_at'],
        );
    }

    /** Exact matches only — an unrelated line must not inflate the panel. */
    public function testMessagesOutsideTheKnownSetAreNotCounted(): void
    {
        $this->log('Something else entirely', 400, '2026-03-01 09:00');

        self::assertSame([], $this->repository->countsByMessage(['GmailPush: unparseable envelope']));
    }

    public function testAnEmptyMessageListAsksNothing(): void
    {
        $this->log('GmailPush: unparseable envelope', 400, '2026-03-01 09:00');

        self::assertSame([], $this->repository->countsByMessage([]));
    }

    // ── the last failure ─────────────────────────────────────────────────────

    public function testTheLastFailureIsTheNewestOne(): void
    {
        $this->log('GmailPushSubscriptionManager: watch failed', 400, '2026-01-01 09:00');
        $this->log('GmailPush: rejected notification', 400, '2026-03-01 09:00');

        $row = $this->repository->findLatestErrorStartingWith('GmailPush', 400);

        self::assertNotNull($row);
        self::assertSame('GmailPush: rejected notification', (string) $row['message']);
    }

    /**
     * The panel is about failures. A warning from the same path is the push
     * code narrating itself, and surfacing it as "the last failure" would send
     * an operator hunting for a problem that never happened.
     */
    public function testEntriesBelowTheErrorThresholdAreNotFailures(): void
    {
        $this->log('GmailPush: staying on polling', 300, '2026-03-01 09:00');

        self::assertNull($this->repository->findLatestErrorStartingWith('GmailPush', 400));
    }

    public function testAnErrorFromAnotherSubsystemIsNotAPushFailure(): void
    {
        $this->log('ImapSync: connection refused', 500, '2026-03-01 09:00');

        self::assertNull($this->repository->findLatestErrorStartingWith('GmailPush', 400));
    }

    // ── the admin log browser ────────────────────────────────────────────────

    /** "Warning and above" is a range, and the range is the whole filter. */
    public function testTheSearchIsFlooredAtTheRequestedLevel(): void
    {
        $this->log('quiet', 200, '2026-03-01 09:00');
        $this->log('loud', 400, '2026-03-01 10:00');

        $found = array_map(
            static fn (LogEntry $entry): string => $entry->message,
            $this->repository->search(300, null, 50, 0),
        );

        self::assertSame(['loud'], $found);
        self::assertSame(1, $this->repository->countSearch(300, null));
    }

    /** Clearing must remove exactly what the same filter listed, and no more. */
    public function testClearingRemovesOnlyWhatTheSameFilterWouldList(): void
    {
        $this->log('quiet', 200, '2026-03-01 09:00');
        $this->log('loud', 400, '2026-03-01 10:00');

        self::assertSame(1, $this->repository->deleteSearch(300, null));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM log_entry'));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * Inserted through DBAL rather than the ORM, because that is how log
     * entries are really written — DoctrineLogHandler bypasses the
     * EntityManager on purpose, and the mapped side of this entity only ever
     * reads. Seeding it the other way would test a path nothing uses.
     */
    private function log(string $message, int $level, string $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO log_entry (channel, level, level_name, message, context, created_at, updated_at)
             VALUES (:channel, :level, :levelName, :message, NULL, :createdAt, :createdAt)',
            [
                'channel'   => 'app',
                'level'     => $level,
                'levelName' => 400 <= $level ? 'ERROR' : 'WARNING',
                'message'   => $message,
                'createdAt' => (new DateTimeImmutable($at))->format('Y-m-d H:i:s'),
            ],
        );

        $this->em->clear();
    }
}
