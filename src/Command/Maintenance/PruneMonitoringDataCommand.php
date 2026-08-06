<?php

declare(strict_types=1);

namespace App\Command\Maintenance;

use App\Repository\Monitoring\LogEntryRepository;
use App\Repository\Push\PushDeliveryRepository;
use App\Service\Monitoring\ProcessHeartbeatService;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention for the three tables that grow on their own.
 *
 * The push delivery log joined the log entries and the heartbeats here rather
 * than getting a command of its own, and that is the shape worth keeping: it is
 * monitoring data written by an automatic process, and one nightly sweep is one
 * schedule entry, one thing to run by hand when a disk fills, and one place to
 * read what this installation forgets. A second command would have needed its
 * own cron line and would have been the one nobody remembers exists.
 *
 * The three windows differ because the tables do. Logs are noisy and mostly
 * uninteresting after a fortnight; heartbeats are tiny; deliveries are the
 * record somebody consults weeks later when a device "stopped getting
 * notifications", which is exactly the question a fortnight's retention answers
 * with silence.
 */
#[AsCommand(
    name: 'app:monitoring:prune',
    description: 'Prune old log entries, push deliveries and dead process heartbeats',
)]
final class PruneMonitoringDataCommand extends Command
{
    /**
     * Push retention, in days. Longer than the log window on purpose: a user
     * reports "notifications stopped" long after they stopped, and a log that
     * has already forgotten the UNREGISTERED that retired their token cannot
     * answer them. Volume allows it — one row per device per state change, and
     * the row holds no payload.
     */
    private const string DEFAULT_PUSH_DAYS = '30';

    public function __construct(
        private readonly LogEntryRepository      $logEntryRepository,
        private readonly PushDeliveryRepository  $pushDeliveryRepository,
        private readonly ProcessHeartbeatService $heartbeats,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Log retention in days', '14')
            ->addOption('push-days', null, InputOption::VALUE_REQUIRED, 'Push delivery retention in days', self::DEFAULT_PUSH_DAYS)
            ->addOption('heartbeat-days', null, InputOption::VALUE_REQUIRED, 'Heartbeat retention in days', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io            = new SymfonyStyle($input, $output);
        $logDays       = max(1, (int) $input->getOption('days'));
        $pushDays      = max(1, (int) $input->getOption('push-days'));
        $heartbeatDays = max(1, (int) $input->getOption('heartbeat-days'));

        $prunedLogs = $this->logEntryRepository->pruneOlderThan(
            new DateTimeImmutable(sprintf('-%d days', $logDays)),
        );

        $prunedDeliveries = $this->pushDeliveryRepository->pruneOlderThan(
            new DateTimeImmutable(sprintf('-%d days', $pushDays)),
        );

        $prunedHeartbeats = $this->heartbeats->pruneStale()
            + $this->heartbeats->pruneOlderThan(
                new DateTimeImmutable(sprintf('-%d days', $heartbeatDays)),
            );

        $io->success(sprintf(
            'Pruned %d log entries (older than %dd), %d push deliveries (older than %dd) and %d dead heartbeats (long-stale, or older than %dd).',
            $prunedLogs,
            $logDays,
            $prunedDeliveries,
            $pushDays,
            $prunedHeartbeats,
            $heartbeatDays,
        ));

        return Command::SUCCESS;
    }
}
