<?php

declare(strict_types=1);

namespace App\Command\Maintenance;

use App\Repository\Maintenance\UpgradeTaskRunRepository;
use App\Service\Maintenance\UpgradeTaskRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs whatever one-time repairs this installation has not run yet.
 *
 * Scheduled, so nobody normally types this. It is here for the two times
 * somebody does: after restoring an old backup, and when a task has used up
 * its attempts and the operator wants to see what it says before deciding
 * anything. `--status` answers the second without running a thing.
 */
#[AsCommand(
    name: 'app:upgrade:run',
    description: 'Run the one-time upgrade tasks this installation has not completed.',
)]
final class RunUpgradeTasksCommand extends Command
{
    public function __construct(
        private readonly UpgradeTaskRunner        $runner,
        private readonly UpgradeTaskRunRepository $runs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('status', null, InputOption::VALUE_NONE, 'Show the ledger without running anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (true === $input->getOption('status')) {
            return $this->showStatus($io);
        }

        return $this->runner->runPending($io);
    }

    private function showStatus(SymfonyStyle $io): int
    {
        $rows = [];

        foreach ($this->runs->findAllOrdered() as $run) {
            $rows[] = [
                $run->name,
                true === $run->isComplete() ? 'done' : 'pending',
                $run->attempts,
                $run->completedAt?->format('Y-m-d H:i') ?? $run->startedAt?->format('Y-m-d H:i') ?? '—',
                $run->lastError ?? '',
            ];
        }

        if (count($rows) === 0) {
            $io->text('No upgrade task has run on this installation yet.');

            return Command::SUCCESS;
        }

        $io->table(['Task', 'State', 'Attempts', 'When', 'Last error'], $rows);

        return Command::SUCCESS;
    }
}
