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
 * Data is disposable, but some regenerations (like the sanitized HTML body)
 * are far cheaper than a full delete-and-resync — this is where they live.
 *
 * Adding a task: implement BackfillTaskInterface. It is auto-tagged and picked
 * up here with no config. Run `app:backfill` with no argument to list them.
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
    }

    protected function configure(): void
    {
        $this
            ->addArgument('task', InputArgument::OPTIONAL, 'The backfill task to run')
            ->setHelp('Run "app:backfill" with no argument to list available tasks.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $name = $input->getArgument('task');

        if (null === $name) {
            $this->listTasks($io);

            return Command::SUCCESS;
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

    private function listTasks(SymfonyStyle $io): void
    {
        if (count($this->tasks) === 0) {
            $io->warning('No backfill tasks are registered.');

            return;
        }

        $io->title('Available backfill tasks');
        $io->listing(array_map(
            static fn(BackfillTaskInterface $task): string => sprintf('%s — %s', $task->getName(), $task->getDescription()),
            array_values($this->tasks),
        ));
        $io->text('Run: <info>app:backfill <task></info>');
    }
}
