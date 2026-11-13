<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Execution;

use App\Infrastructure\Messaging\Execution\ChildProcessExecutionStrategy;
use App\Infrastructure\Messaging\Execution\ChildProcessFailedException;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Infrastructure\Messaging\Message\SyncGraphMessageBatchMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * One job that does not come back cannot hold up the others (#610).
 *
 * With real child processes rather than a fake, because what is being pinned
 * is exactly the part a fake would stand in for: that a process is started
 * per message, that a hung one is killed, and what the Worker is told
 * afterwards. The children are `php -r` one-liners that read the serialized
 * envelope and act on a word in it — HANG, CRASH, or nothing — and write
 * when they started and ended to a file, so overlap can be measured.
 */
final class ChildProcessExecutionStrategyTest extends TestCase
{
    private string $journal;

    private BufferingLogger $logger;

    /** @var list<array{message: object, error: ?\Throwable}> */
    private array $reported = [];

    protected function setUp(): void
    {
        $this->journal = tempnam(sys_get_temp_dir(), 'child-journal-');
        $this->logger  = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        @unlink($this->journal);
        $this->logger->cleanLogs();
    }

    public function testAMessageWhoseChildExitsCleanlyIsReportedHandled(): void
    {
        $strategy = $this->strategy(limit: 2);

        $strategy->execute($this->envelope(new SyncAccountMessage(1)), 'ingest', $this->onHandled(...));
        $strategy->flush($this->onHandled(...), true);

        self::assertCount(1, $this->reported);
        self::assertInstanceOf(SyncAccountMessage::class, $this->reported[0]['message']);
        self::assertNull($this->reported[0]['error'], 'no error: the Worker acknowledges it');
    }

    /**
     * The report: an Outlook account's job hangs, and a Gmail account's job
     * behind it goes through anyway, long before the hung one is given up on.
     */
    public function testAHungChildDoesNotHoldUpAnotherAccount(): void
    {
        $strategy = $this->strategy(limit: 2, deadline: 3.0);

        $started = microtime(true);

        $strategy->execute($this->envelope(new SyncGraphMessageBatchMessage(1, ['HANG'])), 'ingest', $this->onHandled(...));
        $strategy->execute($this->envelope(new SyncGraphMessageBatchMessage(2, ['quick'])), 'ingest', $this->onHandled(...));

        while ([] === $this->reported) {
            $strategy->flush($this->onHandled(...));
            usleep(20_000);
        }

        self::assertLessThan(2.5, microtime(true) - $started, 'the second account did not wait for the first');
        self::assertSame(['quick'], $this->reported[0]['message']->graphIds);
        self::assertNull($this->reported[0]['error']);

        $strategy->flush($this->onHandled(...), true);

        self::assertCount(2, $this->reported);
        self::assertSame(['HANG'], $this->reported[1]['message']->graphIds);
    }

    /**
     * A batch whose child was killed is not lost: it goes back to the Worker
     * as a failure, which puts it on the retry ladder.
     */
    public function testAKilledBatchGoesToTheRetryLadder(): void
    {
        $strategy = $this->strategy(limit: 2, deadline: 1.0);

        $strategy->execute($this->envelope(new SyncGraphMessageBatchMessage(1, ['HANG'])), 'ingest', $this->onHandled(...));
        $strategy->flush($this->onHandled(...), true);

        self::assertCount(1, $this->reported);
        self::assertInstanceOf(ChildProcessFailedException::class, $this->reported[0]['error']);
        self::assertStringContainsString('longer than 1 seconds', $this->reported[0]['error']->getMessage());
    }

    /**
     * A sync whose child was killed is dropped instead: it is asked for again
     * by the schedule, and retrying a hung sync is another hung child.
     */
    public function testAKilledSyncIsDroppedWithOneWarning(): void
    {
        $strategy = $this->strategy(limit: 2, deadline: 1.0);

        // Account 666 is the one whose child hangs: a sync has no string of its own to say HANG with.
        $strategy->execute($this->envelope(new SyncAccountMessage(666, requestedAt: 1)), 'ingest', $this->onHandled(...));
        $strategy->flush($this->onHandled(...), true);

        self::assertCount(1, $this->reported);
        self::assertNull($this->reported[0]['error'], 'acknowledged: off the queue, not retried');

        $warnings = array_values(array_filter($this->logger->cleanLogs(), static fn (array $log): bool => 'warning' === $log[0]));

        self::assertCount(1, $warnings);
    }

    /** A child that crashed is a failure too, not a success. */
    public function testACrashedChildIsReportedAsAFailure(): void
    {
        $strategy = $this->strategy(limit: 2);

        $strategy->execute($this->envelope(new SyncGraphMessageBatchMessage(1, ['CRASH'])), 'ingest', $this->onHandled(...));
        $strategy->flush($this->onHandled(...), true);

        self::assertInstanceOf(ChildProcessFailedException::class, $this->reported[0]['error']);
        self::assertStringContainsString('code 3', $this->reported[0]['error']->getMessage());
    }

    /**
     * The same account's jobs run one after another, as they did on the single
     * worker: a batch never runs beside the sync that queued it, and a mailbox
     * is never asked for by two connections at once.
     */
    public function testTheSameAccountsJobsNeverOverlap(): void
    {
        $strategy = $this->strategy(limit: 4);

        foreach (['SLOW-a', 'SLOW-b', 'SLOW-c'] as $id) {
            $strategy->execute($this->envelope(new SyncGraphMessageBatchMessage(5, [$id])), 'ingest', $this->onHandled(...));
        }

        $strategy->flush($this->onHandled(...), true);

        $spans = $this->spans();

        self::assertCount(3, $spans);

        for ($i = 1; $i < count($spans); ++$i) {
            self::assertGreaterThanOrEqual($spans[$i - 1][1], $spans[$i][0], 'the next one started after the last one ended');
        }

        self::assertSame([['SLOW-a'], ['SLOW-b'], ['SLOW-c']], array_map(
            static fn (array $report): array => $report['message']->graphIds,
            $this->reported,
        ), 'and in the order they arrived');
    }

    /** Different accounts do overlap: that is the point. */
    public function testDifferentAccountsRunSideBySide(): void
    {
        $strategy = $this->strategy(limit: 4);

        foreach ([1, 2, 3] as $account) {
            $strategy->execute($this->envelope(new SyncGraphMessageBatchMessage($account, ['SLOW-' . $account])), 'ingest', $this->onHandled(...));
        }

        self::assertSame(3, $strategy->runningCount());

        $strategy->flush($this->onHandled(...), true);

        $spans = $this->spans();

        self::assertLessThan(min(array_column($spans, 1)), max(array_column($spans, 0)), 'the last to start began before the first to end finished');
    }

    /**
     * execute() comes back only when there is room, so the Worker never takes
     * more off the queue than can run — a message taken off is a message
     * marked delivered.
     */
    public function testExecuteWaitsForRoomBeforeComingBack(): void
    {
        $strategy = $this->strategy(limit: 1);

        $strategy->execute($this->envelope(new SyncGraphMessageBatchMessage(1, ['SLOW-1'])), 'ingest', $this->onHandled(...));

        self::assertCount(1, $this->reported, 'with one slot, the first had to finish before execute() returned');
    }

    /**
     * Told to stop, the children get the grace period and no more, and what
     * never started is handed back to the ladder rather than left marked
     * delivered for an hour.
     */
    public function testStoppingCutsChildrenShortAndHandsBackWhatNeverStarted(): void
    {
        $strategy = $this->strategy(limit: 2, deadline: 30.0, grace: 0.5);

        $strategy->execute($this->envelope(new SyncGraphMessageBatchMessage(1, ['HANG'])), 'ingest', $this->onHandled(...));
        // Same account: waits behind the hung one, never starts.
        $strategy->execute($this->envelope(new SyncGraphMessageBatchMessage(1, ['quick'])), 'ingest', $this->onHandled(...));

        $started = microtime(true);

        $strategy->stop();
        $strategy->flush($this->onHandled(...), true);

        self::assertLessThan(5.0, microtime(true) - $started);
        self::assertCount(2, $this->reported);

        $byId = [];

        foreach ($this->reported as $report) {
            $byId[$report['message']->graphIds[0]] = $report['error'];
        }

        self::assertInstanceOf(ChildProcessFailedException::class, $byId['quick']);
        self::assertStringContainsString('not started', $byId['quick']->getMessage());
        self::assertInstanceOf(ChildProcessFailedException::class, $byId['HANG']);
        self::assertStringContainsString('stopping', $byId['HANG']->getMessage());
    }

    private function strategy(int $limit, float $deadline = 30.0, float $grace = 20.0): ChildProcessExecutionStrategy
    {
        $journal = $this->journal;

        // HANG (or account 666) sleeps past any deadline here, CRASH exits 3, SLOW-* takes a
        // third of a second; every child notes when it ran and under which id.
        $script = <<<'PHP'
            $in = stream_get_contents(STDIN);
            $start = microtime(true);
            if (str_contains($in, 'HANG') || str_contains($in, 'i:666;')) { sleep(60); }
            if (str_contains($in, 'CRASH')) { exit(3); }
            if (preg_match('/SLOW-\w+/', $in, $m)) { usleep(300000); }
            file_put_contents($argv[1], sprintf("%.6f %.6f\n", $start, microtime(true)), FILE_APPEND | LOCK_EX);
            PHP;

        return new ChildProcessExecutionStrategy(
            command: static fn (string $transport): array => [PHP_BINARY, '-r', $script, $journal],
            serializer: new PhpSerializer(),
            keyOf: static fn (object $message): ?string => property_exists($message, 'accountId') ? 'account:' . $message->accountId : null,
            limit: static fn (): int => $limit,
            logger: $this->logger,
            whileBusy: static function (): void {},
            askedAgain: [SyncAccountMessage::class],
            deadlineSeconds: $deadline,
            stopGraceSeconds: $grace,
            write: static function (string $stream, string $line): void {},
        );
    }

    private function envelope(object $message): Envelope
    {
        return new Envelope($message);
    }

    /** By reference, like the Worker's own preAck(): a literal passed there is a fatal error. */
    private function onHandled(Envelope $envelope, string $transport, bool &$acked, ?\Throwable $error): void
    {
        $this->reported[] = ['message' => $envelope->getMessage(), 'error' => $error];
    }

    /** @return list<array{float, float}> start and end of every child that finished, in start order */
    private function spans(): array
    {
        $spans = [];

        foreach (file($this->journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$start, $end] = array_map('floatval', explode(' ', $line));
            $spans[]       = [$start, $end];
        }

        usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $spans;
    }
}
