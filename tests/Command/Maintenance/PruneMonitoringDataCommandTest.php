<?php

declare(strict_types=1);

namespace App\Tests\Command\Maintenance;

use App\Domain\Enum\Job\JobKind;
use App\Domain\Enum\Job\JobState;
use App\Entity\Job\BackgroundJob;
use App\Entity\Monitoring\LogEntry;
use App\Entity\User\TrustedDevice;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The scheduled sweep that keeps monitoring data from becoming the largest
 * thing in the database.
 *
 * Both directions are failure modes: prune too little and the log table grows
 * without bound on an install nobody is watching; prune too much and the
 * dashboard loses the window an operator is currently trying to read. The
 * retention boundary is therefore what the tests below pin down, and they
 * assert on surviving rows rather than the printed counts.
 */
final class PruneMonitoringDataCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CommandTester $command;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $this->command = new CommandTester(
            new Application(self::$kernel)->find('app:monitoring:prune'),
        );
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItDeletesLogEntriesPastTheRetentionWindowAndKeepsTheRest(): void
    {
        $old   = $this->logEntry('-20 days');
        $fresh = $this->logEntry('-1 day');

        $exit = $this->command->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFalse($this->logEntryExists($old));
        self::assertTrue($this->logEntryExists($fresh), 'A day-old entry is inside the 14-day default.');
    }

    public function testTheRetentionWindowFollowsTheDaysOption(): void
    {
        // Inside the 14-day default, outside a 2-day window: the row only goes
        // if the option was actually read.
        $entry = $this->logEntry('-5 days');

        $this->command->execute(['--days' => '2']);

        self::assertFalse($this->logEntryExists($entry));
    }

    /**
     * `--days 0` would mean "delete everything up to this instant", which for a
     * scheduled command is one typo away from wiping the log an operator is
     * mid-investigation on. It is clamped to one day instead.
     */
    public function testANonsensicalRetentionIsClampedRatherThanObeyed(): void
    {
        $entry = $this->logEntry('-1 hour');

        $this->command->execute(['--days' => '0']);

        self::assertTrue($this->logEntryExists($entry));
    }

    public function testItReapsHeartbeatsPastTheirOwnRetentionWindow(): void
    {
        $this->heartbeat('messenger-worker', 'prune-fixture-old', '-40 days');
        $this->heartbeat('messenger-worker', 'prune-fixture-new', '-1 minute');

        $this->command->execute([]);

        self::assertFalse($this->heartbeatExists('prune-fixture-old'));
        self::assertTrue($this->heartbeatExists('prune-fixture-new'));
    }

    /**
     * The two tables whose prune methods nothing called: a finished job past
     * the window and an expired trusted device go, their fresh neighbours stay.
     */
    public function testItPrunesOldFinishedJobsAndExpiredTrustedDevices(): void
    {
        $user = new User();
        $user->email     = 'prune-' . bin2hex(random_bytes(6)) . '@plmail.test';
        $user->nameFirst = 'Prune';
        $user->nameLast  = 'Fixture';
        $user->password  = 'x';
        $this->em->persist($user);
        $this->em->flush();

        $oldJob   = $this->job($user, '-40 days');
        $freshJob = $this->job($user, '-1 day');

        $expired = TrustedDevice::create($user, 'main', 'old laptop', new \DateTimeImmutable('-1 day'));
        $valid   = TrustedDevice::create($user, 'main', 'this laptop', new \DateTimeImmutable('+30 days'));
        $this->em->persist($expired['device']);
        $this->em->persist($valid['device']);
        $this->em->flush();

        $this->command->execute([]);

        self::assertSame(0, $this->rowCount('background_job', $oldJob));
        self::assertSame(1, $this->rowCount('background_job', $freshJob));
        self::assertSame(0, $this->rowCount('trusted_device', (int) $expired['device']->id));
        self::assertSame(1, $this->rowCount('trusted_device', (int) $valid['device']->id));
    }

    /** Nothing to prune is the normal case on a healthy install. */
    public function testAnEmptySweepSucceeds(): void
    {
        self::assertSame(Command::SUCCESS, $this->command->execute([]));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function logEntry(string $age): LogEntry
    {
        $entry = new LogEntry();
        $entry->channel = 'test';
        $entry->level = 200;
        $entry->levelName = 'INFO';
        $entry->message = 'Prune fixture';
        // Reflection because createdAt is private(set): only the entity and
        // its PrePersist may write it. Placing a row before a retention window
        // is exactly the case that needs to, and going through the property
        // would be re-opening it for everyone.
        new \ReflectionProperty(LogEntry::class, 'createdAt')
            ->setValue($entry, new \DateTimeImmutable($age));

        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /** A finished job, its finishedAt moved into the past through DBAL. */
    private function job(User $user, string $finished): int
    {
        $job = new BackgroundJob($user, JobKind::cases()[0]);
        $job->finish(JobState::Done);
        $this->em->persist($job);
        $this->em->flush();

        $this->connection->executeStatement(
            'UPDATE background_job SET finished_at = :at WHERE id = :id',
            ['at' => new \DateTimeImmutable($finished)->format('Y-m-d H:i:s'), 'id' => $job->id],
        );

        return (int) $job->id;
    }

    private function rowCount(string $table, int $id): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE id = ?', $table), [$id]);
    }

    private function logEntryExists(LogEntry $entry): bool
    {
        return 1 === (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM log_entry WHERE id = ?',
            [$entry->id],
        );
    }

    /**
     * Written through DBAL rather than the heartbeat service: the service always
     * stamps NOW(), and an aged row is the whole point of the fixture.
     */
    private function heartbeat(string $type, string $key, string $age): void
    {
        $this->connection->executeStatement(
            'INSERT INTO process_heartbeat (type, beat_key, pid, last_beat_at)
             VALUES (:type, :key, NULL, :beatAt)',
            [
                'type'   => $type,
                'key'    => $key,
                'beatAt' => new \DateTimeImmutable($age)->format('Y-m-d H:i:s'),
            ],
        );
    }

    private function heartbeatExists(string $key): bool
    {
        return 1 === (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM process_heartbeat WHERE beat_key = ?',
            [$key],
        );
    }
}
