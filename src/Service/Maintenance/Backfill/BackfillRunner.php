<?php

declare(strict_types=1);

namespace App\Service\Maintenance\Backfill;

use App\Command\Backfill\BackfillTaskInterface;
use App\Command\Backfill\CountableBackfillTaskInterface;
use App\Command\Backfill\WindowedBackfillTaskInterface;
use App\Domain\Enum\Job\JobState;
use App\Entity\Maintenance\BackfillRun;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\RunBackfillMessage;
use App\Repository\Maintenance\BackfillRun\BackfillRunProgressRepository;
use App\Repository\Maintenance\BackfillRun\BackfillRunRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Running a backfill from Admin → Backfills: queued from the page, run on the
 * maintenance worker, recorded in backfill_run.
 *
 * The same task objects `app:backfill` runs, with the same window parser, so
 * a task behaves the same through either door. What differs is only what is
 * on the other end of the output: a terminal there, the run's row here (see
 * BackfillRunOutput), and nobody to answer a question (see UnattendedStyle).
 *
 * **On the maintenance queue**, where messenger.yaml puts "backfills, rule
 * runs over existing mail, admin sweeps". Other maintenance jobs wait behind a
 * long run; mail does not, because it is handled on queues of its own.
 *
 * **Never retried by Messenger.** execute() catches what a task throws and
 * records it, so a failing repair is reported once rather than run six times
 * on the retry ladder. A message the transport hands out again — because the
 * worker that had it died — finds the run already claimed; see execute().
 */
final readonly class BackfillRunner
{
    public function __construct(
        private BackfillTasks                 $tasks,
        private BackfillWindow                $window,
        private BackfillRunRepository         $runs,
        private BackfillRunProgressRepository $progress,
        private EntityManagerInterface        $em,
        private MessageBusInterface           $bus,
        private LoggerInterface               $logger,
    ) {}

    /**
     * Queue a run of the task, or hand back the one already queued or going.
     *
     * The second click returns the first run instead of starting another: see
     * BackfillRunRepository::activeForTask() for why there is only ever one.
     *
     * @param string|null $window one of BackfillWindow::PRESETS; anything else, the default.
     *                            Ignored for a task that does not re-read mail
     */
    public function queue(BackfillTaskInterface $task, ?string $window, ?User $requestedBy): BackfillRun
    {
        $active = $this->runs->activeForTask($task->getName());

        if (null !== $active) {
            return $active;
        }

        $window = $task instanceof WindowedBackfillTaskInterface
            ? (true === in_array($window, BackfillWindow::PRESETS, true) ? $window : BackfillWindow::DEFAULT)
            : null;

        $run = new BackfillRun($task->getName(), $window, $requestedBy);

        $this->em->persist($run);
        $this->em->flush();

        $this->bus->dispatch(new RunBackfillMessage((int) $run->id));

        return $run;
    }

    /**
     * Run a queued run, on the worker.
     *
     * Claimed first, by one guarded UPDATE: a run that is not queued any more
     * is not started again. That covers the message delivered twice, and the
     * message handed out again after the worker holding it was killed — the
     * transport does that once its redelivery timeout passes. Such a run sits
     * in `running` with nobody behind it; when it has also gone quiet (see
     * BackfillRun::isQuiet()) it is closed as interrupted here, so the page
     * stops showing it as going and the task can be started again. A run that
     * is running and NOT quiet is left alone: that is a slow task whose
     * message came round while it worked, not a dead one.
     */
    public function execute(int $runId): void
    {
        $run = $this->runs->find($runId);

        if (null === $run) {
            return;
        }

        $now = new DateTimeImmutable();

        if (false === $this->progress->claim($runId, $now)) {
            // Read again: the claim's answer came from the table, and the
            // entity may be the one this manager loaded when it was queued.
            $this->em->refresh($run);

            if (true === $run->isQuiet($now)) {
                $this->progress->finish($runId, JobState::Failed, null, 'Interrupted: the worker running it stopped.', $now);
            }

            return;
        }

        $task = $this->tasks->find($run->taskName);

        if (null === $task) {
            // Queued before an update that removed the task.
            $this->progress->finish($runId, JobState::Failed, null, sprintf('There is no backfill called "%s" any more.', $run->taskName), $now);

            return;
        }

        $output = new BackfillRunOutput(
            fn (string $text) => $this->progress->recordOutput($runId, $text, new DateTimeImmutable()),
        );

        $input = new ArrayInput([]);
        $input->setInteractive(false);

        $io = new UnattendedStyle($input, $output);

        try {
            $code = $this->run($task, $run->window, $io);
        } catch (Throwable $e) {
            $io->error(sprintf('%s: %s', $e::class, $e->getMessage()));
            $output->flush();

            $this->progress->finish($runId, JobState::Failed, null, $e->getMessage(), new DateTimeImmutable());

            $this->logger->error('Backfill: run failed', [
                'run'       => $runId,
                'task'      => $run->taskName,
                'exception' => $e,
            ]);

            return;
        }

        $output->flush();

        $this->progress->finish(
            $runId,
            Command::SUCCESS === $code ? JobState::Done : JobState::Failed,
            $code,
            Command::SUCCESS === $code ? null : sprintf('The task ended with exit code %d.', $code),
            new DateTimeImmutable(),
        );
    }

    /**
     * How many messages the task would look at, or null for a task that
     * cannot say. See CountableBackfillTaskInterface.
     */
    public function count(BackfillTaskInterface $task, ?string $window): ?int
    {
        if (false === $task instanceof CountableBackfillTaskInterface) {
            return null;
        }

        $since = $task instanceof WindowedBackfillTaskInterface
            ? $this->window->since(true === in_array($window, BackfillWindow::PRESETS, true) ? (string) $window : BackfillWindow::DEFAULT)
            : null;

        return $task->countCandidates($since);
    }

    /**
     * The task's own run, with the window it was queued with. The heading the
     * console prints first is printed here too, so the stored output reads
     * the same as a terminal's.
     */
    private function run(BackfillTaskInterface $task, ?string $window, UnattendedStyle $io): int
    {
        $io->title(sprintf('Backfill: %s', $task->getName()));
        $io->text($task->getDescription());

        if (false === $task instanceof WindowedBackfillTaskInterface) {
            $io->newLine();

            return $task->run($io);
        }

        $since = $this->window->since($window ?? BackfillWindow::DEFAULT);

        $io->text(null === $since
            ? 'Reading all stored mail.'
            : sprintf('Reading mail received since %s.', $since->format('Y-m-d H:i T')));
        $io->newLine();

        return $task->runSince($io, $since);
    }
}
