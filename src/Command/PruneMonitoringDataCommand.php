<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\LogEntryRepository;
use App\Service\Monitoring\ProcessHeartbeatService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:monitoring:prune',
    description: 'Prune old log entries and dead process heartbeats',
)]
final class PruneMonitoringDataCommand extends Command
{
    public function __construct(
        private readonly LogEntryRepository      $logEntryRepository,
        private readonly ProcessHeartbeatService $heartbeats,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Log retention in days', '14')
            ->addOption('heartbeat-days', null, InputOption::VALUE_REQUIRED, 'Heartbeat retention in days', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io            = new SymfonyStyle($input, $output);
        $logDays       = max(1, (int) $input->getOption('days'));
        $heartbeatDays = max(1, (int) $input->getOption('heartbeat-days'));

        $prunedLogs = $this->logEntryRepository->pruneOlderThan(
            new \DateTimeImmutable(sprintf('-%d days', $logDays)),
        );

        $prunedHeartbeats = $this->heartbeats->pruneOlderThan(
            new \DateTimeImmutable(sprintf('-%d days', $heartbeatDays)),
        );

        $io->success(sprintf(
            'Pruned %d log entries (older than %dd) and %d dead heartbeats (older than %dd).',
            $prunedLogs,
            $logDays,
            $prunedHeartbeats,
            $heartbeatDays,
        ));

        return Command::SUCCESS;
    }
}
