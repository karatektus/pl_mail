<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Service\Maintenance\Backfill\BackfillTasks;
use App\Service\Maintenance\Backfill\BackfillWindow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-off backfills against existing data.
 *
 * Data is disposable, but some regenerations (like the sanitized HTML body or
 * message categories) are far cheaper than a full delete-and-resync — this is
 * where they live.
 *
 * Adding a task: implement BackfillTaskInterface. It is auto-tagged and picked
 * up here with no config.
 *
 * Run `app:backfill` with no argument to pick a task from a list, newest first;
 * pass the task name to run it directly. Admin → Backfills runs the same tasks
 * from the same list (BackfillTasks) on a worker. Under --no-interaction (CI, cron) the argument is
 * required, since there is no one to answer the prompt.
 *
 * --since applies to the tasks that re-read mail (WindowedBackfillTaskInterface)
 * and defaults to the last 24 hours for them; see that interface for why, and
 * BackfillWindow for what it accepts. The table repairs take no window, and say
 * so if they are given one.
 */
#[AsCommand(
    name: 'app:backfill',
    description: 'Run a one-off backfill task against existing data.',
)]
final class BackfillCommand extends Command
{
    /**
     * The window has a default so the tests can build this by hand; it holds
     * no state and needs nothing from the container.
     */
    public function __construct(
        private readonly BackfillTasks  $tasks,
        private readonly BackfillWindow $window = new BackfillWindow(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('task', InputArgument::OPTIONAL, 'The backfill task to run')
            ->addOption(
                'since',
                null,
                InputOption::VALUE_REQUIRED,
                'For tasks that re-read mail: how far back to go — 36h, 7d, 4w, a date (2026-09-01), or "all"',
                BackfillWindow::DEFAULT,
            )
            ->setHelp(
                'Run "app:backfill" with no argument to choose a task interactively.' . "\n\n"
                . 'Tasks that re-read mail (events, insights) look at the last 24 hours unless --since says otherwise: '
                . '--since=7d, --since=2026-09-01, --since=all.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $name = $input->getArgument('task');

        if ([] === $this->tasks->all()) {
            $io->warning('No backfill tasks are registered.');

            return Command::SUCCESS;
        }

        if (null === $name) {
            $name = $this->askForTask($io, $input);

            if (null === $name) {
                return Command::FAILURE;
            }
        }

        $task = $this->tasks->find($name);

        if (null === $task) {
            $io->error(sprintf('Unknown backfill task "%s".', $name));
            $this->listTasks($io);

            return Command::FAILURE;
        }

        if (false === $task instanceof WindowedBackfillTaskInterface) {
            $io->title(sprintf('Backfill: %s', $task->getName()));
            $io->text($task->getDescription());
            $io->newLine();

            // Said, not swallowed: somebody who typed --since=7d and watched a
            // whole table being rewritten should have been told beforehand.
            if (true === $input->hasParameterOption('--since')) {
                $io->note('This task repairs stored data as a whole and takes no --since; it covers everything.');
            }

            return $task->run($io);
        }

        try {
            $since = $this->window->since((string) $input->getOption('since'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->title(sprintf('Backfill: %s', $task->getName()));
        $io->text($task->getDescription());
        $io->text(null === $since
            ? 'Reading all stored mail.'
            : sprintf(
                'Reading mail received since %s. Pass --since=7d, a date, or --since=all to go further back.',
                $since->format('Y-m-d H:i T'),
            ));
        $io->newLine();

        return $task->runSince($io, $since);
    }

    /**
     * Present the registered tasks as a numbered choice. The choice list shows
     * "name — description" so the listing and the picker are the same view;
     * the leading name is split back off the answer.
     */
    private function askForTask(SymfonyStyle $io, InputInterface $input): ?string
    {
        if (false === $input->isInteractive()) {
            $io->error('No task given and the terminal is non-interactive.');
            $this->listTasks($io);

            return null;
        }

        $choices = [];

        foreach ($this->tasks->all() as $taskName => $task) {
            $choices[$taskName] = sprintf('%s — %s', $taskName, $task->getDescription());
        }

        $io->title('Available backfill tasks');

        $answer = $io->choice('Which backfill task should run?', $choices);

        // SymfonyStyle::choice returns the KEY when the choices are an
        // associative array, but older/edge paths can return the label —
        // accept either so the picker cannot break on a Console upgrade.
        if (null !== $this->tasks->find((string) $answer)) {
            return $answer;
        }

        $key = array_search($answer, $choices, true);

        if (false === $key) {
            return null;
        }

        return (string) $key;
    }

    private function listTasks(SymfonyStyle $io): void
    {
        $io->text('Available tasks:');
        $io->listing(array_map(
            static fn(BackfillTaskInterface $task): string => sprintf('%s — %s', $task->getName(), $task->getDescription()),
            array_values($this->tasks->all()),
        ));
        $io->text('Run: <info>app:backfill <task></info>');
    }
}
