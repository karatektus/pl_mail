<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Domain\Interface\UpgradeTaskInterface;
use App\Entity\Maintenance\UpgradeTaskRun;
use App\Repository\Maintenance\UpgradeTaskRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * Runs the one-time upgrade tasks this installation has not run yet.
 *
 * Cheap to ask and expensive only when there is something to do, which is what
 * lets it sit on a ten-minute schedule: the question "is anything pending?" is
 * one indexed lookup per registered task against a table with a handful of
 * rows in it. An install that is up to date pays nothing worth measuring, and
 * an install that has just been updated repairs itself within ten minutes
 * rather than at whatever hour a nightly sweep was pinned to.
 */
final readonly class UpgradeTaskRunner
{
    /**
     * How many times a task that keeps dying is allowed to take the worker
     * down with it.
     *
     * Three, because the failure this exists for is a restart in the middle of
     * a long walk, and a task interrupted three times in a row is not being
     * interrupted — it is broken, and running it a fourth time only delays
     * somebody noticing. The row keeps its lastError, so what stops is the
     * retrying, not the reporting.
     */
    private const int MAX_ATTEMPTS = 3;

    /**
     * @param iterable<UpgradeTaskInterface> $tasks
     */
    public function __construct(
        #[AutowireIterator('app.upgrade_task')]
        private iterable                 $tasks,
        private UpgradeTaskRunRepository $runs,
        private EntityManagerInterface   $em,
        private LoggerInterface          $logger,
    ) {}

    /**
     * @return int a Command exit code
     */
    public function runPending(SymfonyStyle $io): int
    {
        $ran = 0;

        foreach ($this->tasks as $task) {
            $name = $task->getName();
            $run  = $this->runs->findByName($name);

            if (null !== $run && true === $run->isComplete()) {
                continue;
            }

            if (null !== $run && $run->attempts >= self::MAX_ATTEMPTS) {
                continue;
            }

            $io->text(sprintf('→ %s: %s', $name, $task->getDescription()));

            $run ??= new UpgradeTaskRun($name);

            // CLAIMED BEFORE IT RUNS, and flushed, so a container killed
            // halfway leaves a row saying so. Doing it afterwards would make a
            // task that dies mid-walk indistinguishable from one that never
            // started, and the attempt ceiling meaningless.
            $run->recordAttempt();
            $this->em->persist($run);
            $this->em->flush();

            $outcome = $this->execute($task, $io);

            // Re-read rather than reused. A task walking a large table clears
            // the EntityManager between batches — the backfills all do — and
            // the instance above is detached by the time it returns. Writing
            // through it would flush nothing and the task would run again
            // forever.
            $run = $this->runs->findByName($name);

            if (null === $run) {
                continue;
            }

            if (null === $outcome) {
                $run->recordSuccess();
                ++$ran;
            } else {
                $run->recordFailure($outcome);

                $this->logger->error('Upgrade task failed', [
                    'task'     => $name,
                    'attempts' => $run->attempts,
                    'error'    => $outcome,
                ]);
            }

            $this->em->flush();
        }

        if (0 === $ran) {
            $io->text('Nothing pending.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return string|null null on success, the reason otherwise
     */
    private function execute(UpgradeTaskInterface $task, SymfonyStyle $io): ?string
    {
        try {
            $code = $task->run($io);
        } catch (Throwable $e) {
            return sprintf('%s: %s', $e::class, $e->getMessage());
        }

        return Command::SUCCESS === $code ? null : sprintf('exit code %d', $code);
    }
}
