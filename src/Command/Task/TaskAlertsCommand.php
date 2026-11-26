<?php

declare(strict_types=1);

namespace App\Command\Task;

use App\Repository\Task\TaskAlertDeliveryRepository;
use App\Service\Task\Alert\TaskAlertDeliverer;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:tasks:alerts',
    description: 'Deliver task reminders that have come due, and prune the records of ones long past',
)]
final class TaskAlertsCommand extends Command
{
    /** Longer than the lookback, so a record outlives the window that could ask about it. */
    private const string KEEP_DELIVERIES_FOR = '-7 days';

    public function __construct(
        private readonly TaskAlertDeliverer          $deliverer,
        private readonly TaskAlertDeliveryRepository $deliveries,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what is due without delivering it or writing anything down');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $now = new DateTimeImmutable();
        $due = $this->deliverer->due($now);

        if (true === $input->getOption('dry-run')) {
            foreach ($due as $task) {
                $io->text(sprintf('→ task #%d due %s', (int) $task->id, $task->reminderAt?->format('Y-m-d H:i:s')));
            }

            $io->success(sprintf('%d reminder(s) due.', count($due)));

            return Command::SUCCESS;
        }

        $delivered = 0;

        foreach ($due as $task) {
            if (true === $this->deliverer->deliver($task, $now)) {
                ++$delivered;
            }
        }

        // After the sweep, so a prune that fails on a lock cannot cost its deliveries.
        $pruned = $this->deliveries->pruneBefore($now->modify(self::KEEP_DELIVERIES_FOR));

        if (0 !== $delivered || 0 !== $pruned) {
            $io->success(sprintf('Delivered %d reminder(s); pruned %d record(s).', $delivered, $pruned));
        }

        return Command::SUCCESS;
    }
}
