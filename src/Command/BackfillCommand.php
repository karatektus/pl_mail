<?php

declare(strict_types=1);

namespace App\Command;

use App\Command\Backfill\BackfillTaskInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

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
 * Run `app:backfill` with no argument to pick a task from a list; pass the task
 * name to run it directly. Under --no-interaction (CI, cron) the argument is
 * required, since there is no one to answer the prompt.
 */
#[AsCommand(
    name: 'app:backfill',
    description: 'Run a one-off backfill task against existing data.',
)]
final class BackfillCommand extends Command
{
    /** @var array<string, BackfillTaskInterface> */
    private array $tasks = [];

    /**
     * @param iterable<BackfillTaskInterface> $tasks
     */
    public function __construct(
        #[AutowireIterator('app.backfill_task')]
        iterable $tasks,
    ) {
        parent::__construct();

        foreach ($tasks as $task) {
            $this->tasks[$task->getName()] = $task;
        }

        ksort($this->tasks);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('task', InputArgument::OPTIONAL, 'The backfill task to run')
            ->setHelp('Run "app:backfill" with no argument to choose a task interactively.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $name = $input->getArgument('task');

        if (count($this->tasks) === 0) {
            $io->warning('No backfill tasks are registered.');

            return Command::SUCCESS;
        }

        if (null === $name) {
            $name = $this->askForTask($io, $input);

            if (null === $name) {
                return Command::FAILURE;
            }
        }

        if (false === isset($this->tasks[$name])) {
            $io->error(sprintf('Unknown backfill task "%s".', $name));
            $this->listTasks($io);

            return Command::FAILURE;
        }

        $task = $this->tasks[$name];

        $io->title(sprintf('Backfill: %s', $task->getName()));
        $io->text($task->getDescription());
        $io->newLine();

        return $task->run($io);
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

        foreach ($this->tasks as $taskName => $task) {
            $choices[$taskName] = sprintf('%s — %s', $taskName, $task->getDescription());
        }

        $io->title('Available backfill tasks');

        $answer = $io->choice('Which backfill task should run?', $choices);

        // SymfonyStyle::choice returns the KEY when the choices are an
        // associative array, but older/edge paths can return the label —
        // accept either so the picker cannot break on a Console upgrade.
        if (true === isset($this->tasks[$answer])) {
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
            array_values($this->tasks),
        ));
        $io->text('Run: <info>app:backfill <task></info>');
    }
}
