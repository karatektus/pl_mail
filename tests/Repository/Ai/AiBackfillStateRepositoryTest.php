<?php

declare(strict_types=1);

namespace App\Tests\Repository\Ai;

use App\Domain\Enum\Ai\BackfillPauseReason;
use App\Domain\Enum\Ai\BackfillStatus;
use App\Repository\Ai\AiBackfillStateRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The one row, exercised against a real PostgreSQL.
 *
 * Every write in that repository is a single statement with its guard in the
 * WHERE clause and its cursor arithmetic in jsonb_set, which is the only way
 * two processes can share the row safely — and also the only way a typo in the
 * SQL survives every unit test and fails on a worker at three in the morning.
 * None of it is expressible in a mock, so none of it is mocked.
 *
 * In a transaction that is rolled back: the table is a singleton, so a test
 * that left a row behind would change what the next one sees.
 */
final class AiBackfillStateRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private AiBackfillStateRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->repository = self::getContainer()->get(AiBackfillStateRepository::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testARunIsClaimedOnceAndRefusedTheSecondTime(): void
    {
        $now = new DateTimeImmutable();

        self::assertTrue($this->repository->begin('nomic-embed-text', [1, 2], $now));

        // The guard that stops two administrators starting two walks over one
        // mailbox — twice the requests to the machine that is the bottleneck.
        self::assertFalse($this->repository->begin('nomic-embed-text', [1, 2], $now));

        $run = $this->repository->current();

        self::assertSame(BackfillStatus::Running, $run->status);
        self::assertSame('nomic-embed-text', $run->model);
        self::assertSame([1, 2], $run->unfinishedUserIds());
        self::assertNull($run->cursorFor(1));
    }

    public function testAChunkMovesOneMailboxAndLeavesTheOtherAlone(): void
    {
        $now = new DateTimeImmutable();

        $this->repository->begin('nomic-embed-text', [1, 2], $now);
        $this->repository->recordChunk(1, 4711, false, 2, $now);

        $run = $this->repository->current();

        self::assertSame(4711, $run->cursorFor(1));
        self::assertNull($run->cursorFor(2), 'jsonb_set touches one key, not the object');
        self::assertSame(2, $run->failures);
        self::assertSame(BackfillStatus::Running, $run->status);
    }

    public function testEveryMailboxFinishedIsWhatCompletesARun(): void
    {
        $now = new DateTimeImmutable();

        $this->repository->begin('nomic-embed-text', [1, 2], $now);
        $this->repository->recordChunk(1, 4711, true, 0, $now);

        self::assertFalse($this->repository->current()->everyMailboxFinished());

        $this->repository->recordChunk(2, 99, true, 0, $now);

        self::assertTrue($this->repository->current()->everyMailboxFinished());

        $this->repository->markComplete($now);

        self::assertSame(BackfillStatus::Complete, $this->repository->current()->status);
        self::assertFalse($this->repository->current()->isLive());
    }

    /**
     * A pause that lifts on its own keeps the chain alive; the operator's does
     * not. The handler reads exactly this to decide whether to carry on.
     */
    public function testOnlyTheSelfResumingPausesCountAsLive(): void
    {
        $now = new DateTimeImmutable();

        $this->repository->begin('nomic-embed-text', [1], $now);

        $this->repository->yieldFor(BackfillPauseReason::Interactive, $now);
        self::assertTrue($this->repository->current()->isLive());

        $this->repository->pause(BackfillPauseReason::Operator, $now);
        self::assertFalse($this->repository->current()->isLive());
    }

    public function testResumePicksUpAnOperatorPauseAndNotAYield(): void
    {
        $now = new DateTimeImmutable();

        $this->repository->begin('nomic-embed-text', [1], $now);
        $this->repository->recordChunk(1, 500, false, 0, $now);
        $this->repository->pause(BackfillPauseReason::Operator, $now);

        self::assertTrue($this->repository->resume($now));

        $run = $this->repository->current();

        self::assertSame(BackfillStatus::Running, $run->status);
        self::assertSame(500, $run->cursorFor(1), 'a resume does not lose the cursor');

        // Already going, and not stale: nothing to resume.
        self::assertFalse($this->repository->resume($now));
    }

    public function testEmptyChunksAccumulateUntilTheRunIsFailed(): void
    {
        $now = new DateTimeImmutable();

        $this->repository->begin('nomic-embed-text', [1], $now);

        self::assertSame(1, $this->repository->noteEmptyChunk($now));
        self::assertSame(2, $this->repository->noteEmptyChunk($now));

        // One chunk that works clears the count, or a host that blinks twice a
        // day would eventually fail a run that is otherwise fine.
        $this->repository->recordChunk(1, 10, false, 0, $now);
        self::assertSame(0, $this->repository->current()->emptyBatches);

        $this->repository->markFailed('no_answer', $now);

        $run = $this->repository->current();

        self::assertSame(BackfillStatus::Failed, $run->status);
        self::assertSame('no_answer', $run->lastError);
    }

    public function testInteractiveActivityIsStampedAndNeverMovesBackwards(): void
    {
        $now = new DateTimeImmutable('2026-08-27 09:00:00');

        $this->repository->touchInteractive($now);
        self::assertEquals($now, $this->repository->current()->interactiveSeenAt);

        // A worker with a clock a second behind must not be able to un-say that
        // somebody is using the composer.
        $this->repository->touchInteractive($now->modify('-1 minute'));
        self::assertEquals($now, $this->repository->current()->interactiveSeenAt);
    }
}
