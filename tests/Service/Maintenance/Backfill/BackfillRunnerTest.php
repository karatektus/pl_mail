<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance\Backfill;

use App\Command\Backfill\BackfillTaskInterface;
use App\Command\Backfill\WindowedBackfillTaskInterface;
use App\Domain\Enum\Job\JobState;
use App\Entity\Maintenance\BackfillRun;
use App\Infrastructure\Messaging\Message\RunBackfillMessage;
use App\Repository\Maintenance\BackfillRun\BackfillRunProgressRepository;
use App\Repository\Maintenance\BackfillRun\BackfillRunRepository;
use App\Service\Maintenance\Backfill\BackfillRunner;
use App\Service\Maintenance\Backfill\BackfillTasks;
use App\Service\Maintenance\Backfill\BackfillWindow;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A backfill started from the admin page runs once, on the worker, and its
 * row says how it went.
 *
 * The failures this guards are all quiet ones. A run that throws must end as
 * Failed with the reason, not stay "running" for ever or be retried six times
 * by Messenger. A message handed out twice must not run the task twice. A
 * worker killed mid-run must not leave a row the page shows as going for good.
 * And a task that asks "are you sure?" must not take the worker's silence for
 * a no — `rethread` would print its warning, do nothing and report success.
 *
 * The real ledger in a real database, rolled back after each test; the tasks
 * are stubs, so what is tested is the runner rather than any one repair.
 */
final class BackfillRunnerTest extends KernelTestCase
{
    private Connection $connection;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        // Never committed, so the suite leaves nothing behind.
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testQueueingWritesARunAndSendsItToTheMaintenanceWorker(): void
    {
        $task   = $this->task('repair');
        $runner = $this->runner($task);

        $run = $runner->queue($task, '7d', null);

        self::assertNotNull($run->id);
        self::assertSame(JobState::Queued, $run->state);
        self::assertNull($run->window, 'A table repair takes no window, whatever the page posted');

        $sent = $this->maintenance()->getSent();

        self::assertCount(1, $sent);
        self::assertInstanceOf(RunBackfillMessage::class, $sent[0]->getMessage());
        self::assertSame($run->id, $sent[0]->getMessage()->runId);
    }

    /**
     * The second click is somebody who did not see the first one start. It
     * gets the first run back rather than a second walk of the same table.
     */
    public function testASecondRunOfTheSameTaskIsTheFirstOne(): void
    {
        $task   = $this->task('repair');
        $runner = $this->runner($task);

        $first  = $runner->queue($task, null, null);
        $second = $runner->queue($task, null, null);

        self::assertSame($first->id, $second->id);
        self::assertCount(1, $this->maintenance()->getSent());
    }

    public function testAWindowThePageDoesNotOfferBecomesTheDefault(): void
    {
        $task = $this->windowedTask('events');

        $run = $this->runner($task)->queue($task, '10y', null);

        self::assertSame(BackfillWindow::DEFAULT, $run->window);
    }

    public function testARunRecordsWhatTheTaskPrintedAndThatItSucceeded(): void
    {
        $task   = $this->task('repair');
        $runner = $this->runner($task);
        $id     = (int) $runner->queue($task, null, null)->id;

        $runner->execute($id);

        $run = $this->reload($id);

        self::assertSame(1, $task->runs);
        self::assertSame(JobState::Done, $run->state);
        self::assertSame(Command::SUCCESS, $run->exitCode);
        self::assertNotNull($run->finishedAt);
        self::assertStringContainsString('Repaired 3 rows.', $run->output);
    }

    public function testAWindowedRunReadsTheWindowItWasQueuedWith(): void
    {
        $task   = $this->windowedTask('events');
        $runner = $this->runner($task);
        $id     = (int) $runner->queue($task, BackfillWindow::ALL, null)->id;

        $runner->execute($id);

        self::assertSame([null], $task->windows, '"all" reaches the task as no limit');
    }

    /**
     * Caught and recorded, not rethrown: a rethrow would put the message on
     * the retry ladder and run a failing repair five more times.
     */
    public function testATaskThatThrowsEndsAsFailedWithTheReason(): void
    {
        $task   = $this->task('repair', throws: true);
        $runner = $this->runner($task);
        $id     = (int) $runner->queue($task, null, null)->id;

        $runner->execute($id);

        $run = $this->reload($id);

        self::assertSame(JobState::Failed, $run->state);
        self::assertSame('The table is on fire.', $run->failureReason);
        self::assertStringContainsString('The table is on fire.', $run->output);
    }

    public function testATaskThatReturnsFailureEndsAsFailed(): void
    {
        $task   = $this->task('repair', exitCode: Command::FAILURE);
        $runner = $this->runner($task);
        $id     = (int) $runner->queue($task, null, null)->id;

        $runner->execute($id);

        self::assertSame(JobState::Failed, $this->reload($id)->state);
        self::assertSame(Command::FAILURE, $this->reload($id)->exitCode);
    }

    public function testAMessageDeliveredTwiceRunsTheTaskOnce(): void
    {
        $task   = $this->task('repair');
        $runner = $this->runner($task);
        $id     = (int) $runner->queue($task, null, null)->id;

        $runner->execute($id);
        $runner->execute($id);

        self::assertSame(1, $task->runs);
        self::assertSame(JobState::Done, $this->reload($id)->state);
    }

    /**
     * The worker died with the run in hand; the transport hands the message
     * out again later. The run is closed, so the page stops showing it as
     * going and the task can be started again — and it is not started from
     * here, because nobody chose to run it a second time.
     */
    public function testARunWhoseWorkerWentAwayIsClosedWhenItsMessageComesBack(): void
    {
        $task   = $this->task('repair');
        $runner = $this->runner($task);
        $id     = (int) $runner->queue($task, null, null)->id;

        // Written from PHP's clock rather than NOW(), so the database and
        // the runner agree on what two hours ago was whatever their zones.
        $this->connection->executeStatement(
            "UPDATE backfill_run SET state = 'running', started_at = :started WHERE id = :id",
            ['id' => $id, 'started' => new DateTimeImmutable('-2 hours')],
            ['started' => Types::DATETIME_IMMUTABLE],
        );

        $runner->execute($id);

        $run = $this->reload($id);

        self::assertSame(0, $task->runs);
        self::assertSame(JobState::Failed, $run->state);
        self::assertStringStartsWith('Interrupted', (string) $run->failureReason);
    }

    /** Running and still printing: a slow task, left alone. */
    public function testARunThatIsStillTalkingIsLeftAlone(): void
    {
        $task   = $this->task('repair');
        $runner = $this->runner($task);
        $id     = (int) $runner->queue($task, null, null)->id;

        $this->connection->executeStatement(
            "UPDATE backfill_run SET state = 'running', started_at = :started, last_output_at = :now WHERE id = :id",
            ['id' => $id, 'started' => new DateTimeImmutable('-2 hours'), 'now' => new DateTimeImmutable()],
            ['started' => Types::DATETIME_IMMUTABLE, 'now' => Types::DATETIME_IMMUTABLE],
        );

        $runner->execute($id);

        self::assertSame(JobState::Running, $this->reload($id)->state);
    }

    /**
     * On the worker nobody can answer, and an unanswered confirm() is its
     * default — no — which would make `rethread` stop after its warning and
     * report success. Pressing Run was the answer.
     */
    public function testAQuestionTheTaskAsksIsAnsweredYesAndSaidSo(): void
    {
        $task   = $this->task('rethread', asks: true);
        $runner = $this->runner($task);
        $id     = (int) $runner->queue($task, null, null)->id;

        $runner->execute($id);

        $run = $this->reload($id);

        self::assertSame(1, $task->runs);
        self::assertStringContainsString('Rebuild all threads now? yes', $run->output);
        self::assertStringContainsString('Repaired 3 rows.', $run->output, 'the task went ahead');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function runner(BackfillTaskInterface ...$tasks): BackfillRunner
    {
        $container = self::getContainer();

        return new BackfillRunner(
            new BackfillTasks($tasks),
            new BackfillWindow(),
            $container->get(BackfillRunRepository::class),
            $container->get(BackfillRunProgressRepository::class),
            $this->em,
            $container->get(MessageBusInterface::class),
            new NullLogger(),
        );
    }

    private function maintenance(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.maintenance');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function reload(int $id): BackfillRun
    {
        $this->em->clear();

        $run = $this->em->find(BackfillRun::class, $id);
        self::assertNotNull($run);

        return $run;
    }

    /**
     * @return BackfillTaskInterface&object{runs: int}
     */
    private function task(string $name, int $exitCode = Command::SUCCESS, bool $throws = false, bool $asks = false): object
    {
        return new class ($name, $exitCode, $throws, $asks) implements BackfillTaskInterface {
            public int $runs = 0;

            public function __construct(
                private readonly string $name,
                private readonly int    $exitCode,
                private readonly bool   $throws,
                private readonly bool   $asks,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return 'A stub repair.';
            }

            public function getAddedOn(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-11-01');
            }

            public function isSafeToRunAgain(): bool
            {
                return true;
            }

            public function run(SymfonyStyle $io): int
            {
                if (true === $this->asks && false === $io->confirm('Rebuild all threads now?', false)) {
                    return Command::SUCCESS;
                }

                ++$this->runs;

                if (true === $this->throws) {
                    throw new RuntimeException('The table is on fire.');
                }

                $io->text('Repaired 3 rows.');

                return $this->exitCode;
            }
        };
    }

    /**
     * @return WindowedBackfillTaskInterface&object{windows: list<?string>}
     */
    private function windowedTask(string $name): object
    {
        return new class ($name) implements WindowedBackfillTaskInterface {
            /** @var list<?string> */
            public array $windows = [];

            public function __construct(private readonly string $name) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return 'A stub that re-reads mail.';
            }

            public function getAddedOn(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-11-01');
            }

            public function isSafeToRunAgain(): bool
            {
                return true;
            }

            public function run(SymfonyStyle $io): int
            {
                return $this->runSince($io, null);
            }

            public function runSince(SymfonyStyle $io, ?DateTimeImmutable $since): int
            {
                $this->windows[] = $since?->format('Y-m-d H:i');

                return Command::SUCCESS;
            }
        };
    }
}
