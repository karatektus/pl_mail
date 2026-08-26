<?php

declare(strict_types=1);

namespace App\Tests\Repository\Ai;

use App\Domain\DTO\Ai\AiCallTiming;
use App\Domain\Enum\Ai\AiCallFeature;
use App\Repository\Ai\AiCallMetricRepository;
use App\Service\Ai\AiCallRecorder;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the performance panel is allowed to claim about the model.
 *
 * The measurement that matters here is a rate, and a rate has exactly one way
 * to go quietly wrong: a call with no generation phase counted as a call that
 * generated nothing. An embedding has no generation phase — /api/embed returns
 * no eval_count and no eval_duration at all — so on a mailbox being backfilled
 * there are far more of those rows than of anything else. Read as zero, they
 * would sit in the middle of every percentile and drag p50 to the floor, and
 * the panel would report a perfectly healthy model as running at a few tokens
 * a second. testAnEmbeddingDoesNotDragTheGenerationRateDown is the whole point
 * of this file; the rest is scaffolding around it.
 *
 * A real database rather than a double, deliberately: percentile_cont, FILTER
 * and the bigint arithmetic ARE the thing under test, and none of them exist
 * in PHP.
 */
final class AiCallMetricRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private AiCallMetricRepository $repository;
    private AiCallRecorder $recorder;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);

        // Built here rather than pulled from the container: both take nothing
        // but the connection, and until a controller injects them the container
        // inlines them away.
        $this->repository = new AiCallMetricRepository($this->connection);
        $this->recorder   = new AiCallRecorder($this->connection, new NullLogger());

        $this->connection->beginTransaction();

        // The table is the fixture, and it is shared with anything else in the
        // suite that happens to reach a model. Emptied inside the transaction,
        // so the rollback puts it back.
        $this->connection->executeStatement('DELETE FROM ai_call_metric');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * Three generations at a known rate, and two embeddings that generated
     * nothing. The median of the three is the answer; the embeddings must not
     * appear in it at all.
     */
    public function testAnEmbeddingDoesNotDragTheGenerationRateDown(): void
    {
        // 100 tokens in 10s, 200 in 10s, 300 in 10s → 10, 20 and 30 tok/s.
        foreach ([100, 200, 300] as $tokens) {
            $this->recorder->record(
                AiCallFeature::WritingHelp,
                'qwen3:30b',
                true,
                null,
                new AiCallTiming(evalTokens: $tokens, evalDurationNs: 10_000_000_000),
            );
        }

        // Two embeddings under the SAME feature would be the trap; they are
        // under their own, but the filter is what protects the number either
        // way, so record them and prove the writing bucket is untouched.
        foreach ([1, 2] as $ignored) {
            $this->recorder->record(
                AiCallFeature::MailIndex,
                'qwen3-embedding:0.6b',
                true,
                null,
                new AiCallTiming(promptTokens: 512, promptDurationNs: 1_000_000_000),
            );
        }

        $rows = $this->byBucket($this->repository->perFeatureSince(new DateTimeImmutable('-1 hour')));

        self::assertSame(20.0, $rows['writing_help']['genTokensPerSecondP50']);

        // Null, not 0.0. "No embedding call has a generation phase" and "the
        // generation ran at nothing" are different statements, and a panel that
        // printed 0 tok/s here would be reporting a fault that is not there.
        self::assertNull($rows['mail_index']['genTokensPerSecondP50']);

        // The prompt side of an embedding IS measured, so that one has a number.
        self::assertSame(512.0, $rows['mail_index']['promptTokensPerSecondP50']);
    }

    /** A cold load is a second or more; a resident model answers in milliseconds. */
    public function testOnlyRealColdLoadsAreCountedAsOne(): void
    {
        $this->recorder->record(
            AiCallFeature::WritingHelp,
            'qwen3:30b',
            true,
            null,
            new AiCallTiming(loadDurationNs: 13_000_000_000),
        );

        $this->recorder->record(
            AiCallFeature::WritingHelp,
            'qwen3:30b',
            true,
            null,
            new AiCallTiming(loadDurationNs: 4_000_000),
        );

        $rows = $this->byBucket($this->repository->perFeatureSince(new DateTimeImmutable('-1 hour')));

        self::assertSame(2, $rows['writing_help']['calls']);
        self::assertSame(1, $rows['writing_help']['coldLoads']);
    }

    public function testAFailedCallIsCountedAndCategorised(): void
    {
        $this->recorder->record(
            AiCallFeature::Categorise,
            'qwen3:30b',
            false,
            'timeout',
            AiCallTiming::none(),
        );

        $rows = $this->byBucket($this->repository->perFeatureSince(new DateTimeImmutable('-1 hour')));

        self::assertSame(1, $rows['categorise']['calls']);
        self::assertSame(1, $rows['categorise']['errors']);

        $latest = $this->repository->latest();

        self::assertNotNull($latest);
        self::assertFalse($latest['succeeded']);
        self::assertSame('timeout', $latest['errorKind']);
    }

    /** Outside the window is outside the answer. */
    public function testTheWindowIsRespected(): void
    {
        $this->recorder->record(
            AiCallFeature::WritingHelp,
            'qwen3:30b',
            true,
            null,
            new AiCallTiming(evalTokens: 10, evalDurationNs: 1_000_000_000),
        );

        $this->connection->executeStatement(
            "UPDATE ai_call_metric SET created_at = NOW() - INTERVAL '2 hours'",
        );

        self::assertSame([], $this->repository->perFeatureSince(new DateTimeImmutable('-1 hour')));
    }

    /** No calls at all is an empty answer, not a row of zeroes. */
    public function testAnEmptyTableIsEmptyRatherThanZeroed(): void
    {
        self::assertSame([], $this->repository->perFeatureSince(new DateTimeImmutable('-1 hour')));
        self::assertSame([], $this->repository->perModelSince(new DateTimeImmutable('-1 hour')));
        self::assertNull($this->repository->latest());
    }

    /**
     * A metrics write must never be the thing that breaks a reply.
     *
     * The recorder is handed a connection that throws on everything, which is
     * what a container that came up before the migration lock released looks
     * like from in here.
     */
    public function testAFailedWriteIsSwallowed(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('no such table'));

        $recorder = new AiCallRecorder($connection, new NullLogger());

        $recorder->record(AiCallFeature::SearchQuery, 'm', true, null, AiCallTiming::none());

        // Reaching here at all is the assertion.
        $this->addToAssertionCount(1);
    }

    /**
     * @param list<array{bucket: string, ...}> $rows
     *
     * @return array<string, array<string, mixed>>
     */
    private function byBucket(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[$row['bucket']] = $row;
        }

        return $out;
    }
}
