<?php

declare(strict_types=1);

namespace App\Command\Imap;

use App\Repository\Mail\MailboxRepository;
use App\Service\Monitoring\ProcessHeartbeatService;
use App\Service\Monitoring\WorkerRestartSignal;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:imap:supervise',
    description: 'Spawns and supervises one app:imap:idle process per IDLE-enabled mailbox',
)]
final class ImapSuperviseCommand extends Command
{
    private const int DEFAULT_POLL_INTERVAL = 60;
    private const int MIN_BACKOFF_SECONDS = 1;
    private const int MAX_BACKOFF_SECONDS = 30;
    private const int BACKOFF_RESET_AFTER_UPTIME_SECONDS = 120;

    /** @var array<int, Process> */
    private array $processes = [];

    /** @var array<int, int> mailboxId => current backoff seconds */
    private array $backoff = [];

    /** @var array<int, float> mailboxId => microtime the process was (re)started */
    private array $startedAt = [];

    /** @var array<int, float> mailboxId => microtime we're allowed to restart at, after a failure */
    private array $restartNotBefore = [];

    private bool $shouldStop = false;

    public function __construct(
        private readonly MailboxRepository $mailboxRepository,
        private readonly ProcessHeartbeatService $heartbeats,
        private readonly WorkerRestartSignal $restartSignal,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'interval',
            null,
            InputOption::VALUE_REQUIRED,
            'Seconds between reconciliation polls of the mailbox table',
            (string) self::DEFAULT_POLL_INTERVAL,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $intervalOption = $input->getOption('interval');
        $pollInterval = (int) $intervalOption;

        if ($pollInterval < 1) {
            $pollInterval = self::DEFAULT_POLL_INTERVAL;
        }

        $this->registerSignalHandlers($io);

        $io->title('IMAP supervisor starting');
        $io->text('Poll interval: ' . $pollInterval . 's');

        $this->reconcile($io);

        $startedAt  = microtime(true);
        $lastPollAt = $startedAt;

        while ($this->shouldStop === false) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            if ($this->shouldStop === true) {
                break;
            }

            $this->checkChildren($io);

            $now = microtime(true);
            $elapsedSincePoll = $now - $lastPollAt;

            if ($elapsedSincePoll >= $pollInterval) {
                // Checked on the reconcile cadence, not the 500ms loop: this
                // hits a shared cache pool, and an admin waiting one poll
                // interval for a restart is fine.
                //
                // The supervisor is not a Messenger worker, so Symfony's
                // StopWorkerOnRestartSignalListener never sees it — reading
                // the same signal here is what makes one "restart workers"
                // button cover all three long-running containers.
                if (true === $this->restartSignal->isRequestedSince($startedAt)) {
                    $io->text('Restart requested by an administrator, shutting down…');
                    $this->shouldStop = true;

                    break;
                }

                $this->reconcile($io);
                $lastPollAt = microtime(true);
            }
            $this->heartbeats->beat(
                ProcessHeartbeatService::TYPE_IMAP_SUPERVISE,
                'main',
                ['children' => count($this->processes)],
            );
            usleep(500000);
        }

        $this->shutdownAllChildren($io);

        $this->heartbeats->clear(ProcessHeartbeatService::TYPE_IMAP_SUPERVISE, 'main');

        $io->success('IMAP supervisor stopped cleanly');

        return Command::SUCCESS;
    }

    private function registerSignalHandlers(SymfonyStyle $io): void
    {
        if (function_exists('pcntl_async_signals') === false) {
            $io->warning('pcntl extension not available — signal handling disabled');

            return;
        }

        pcntl_async_signals(true);

        $handler = function (int $signal) use ($io): void {
            $io->text('Received signal ' . $signal . ', shutting down…');
            $this->shouldStop = true;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    private function reconcile(SymfonyStyle $io): void
    {
        $mailboxes = $this->mailboxRepository->findIdleEnabledAndSyncEnabled();

        $activeMailboxIds = [];

        foreach ($mailboxes as $mailbox) {
            $mailboxId = $mailbox->getId();
            $activeMailboxIds[] = $mailboxId;

            if (array_key_exists($mailboxId, $this->processes) === false) {
                $this->startChild($io, $mailboxId);
            }
        }

        foreach (array_keys($this->processes) as $trackedMailboxId) {
            if (in_array($trackedMailboxId, $activeMailboxIds, true) === false) {
                $io->text('Mailbox ' . $trackedMailboxId . ' no longer IDLE-enabled, stopping process');
                $this->stopChild($trackedMailboxId);
            }
        }

        $this->reapHeartbeats($io, $activeMailboxIds);
    }

    /**
     * The supervisor is the only process that knows the full set of mailboxes
     * that should be idling, so it owns cleanup of heartbeat rows left behind
     * by mailboxes that are gone (or children that were killed hard). Rows for
     * still-active mailboxes are left alone even when their child is down —
     * those should show up stale on the dashboard, not silently vanish.
     *
     * @param list<int> $activeMailboxIds
     */
    private function reapHeartbeats(SymfonyStyle $io, array $activeMailboxIds): void
    {
        $orphans = $this->heartbeats->clearOrphans(
            ProcessHeartbeatService::TYPE_IMAP_IDLE,
            array_map(strval(...), $activeMailboxIds),
        );

        $stale = $this->heartbeats->pruneStale();

        if ($orphans > 0 || $stale > 0) {
            $io->text(sprintf('Reaped %d orphaned and %d stale heartbeat(s)', $orphans, $stale));
        }
    }

    private function checkChildren(SymfonyStyle $io): void
    {
        foreach ($this->processes as $mailboxId => $process) {
            if ($process->isRunning() === true) {
                $uptime = microtime(true) - $this->startedAt[$mailboxId];

                if ($uptime >= self::BACKOFF_RESET_AFTER_UPTIME_SECONDS) {
                    $this->backoff[$mailboxId] = self::MIN_BACKOFF_SECONDS;
                }

                continue;
            }

            $exitCode = $process->getExitCode();
            $errorOutput = $process->getErrorOutput();

            $io->warning(
                'IDLE process for mailbox ' . $mailboxId
                . ' died (exit code ' . $exitCode . ')',
            );

            if ($errorOutput !== '') {
                $this->logger->error('IDLE process stderr for mailbox ' . $mailboxId, [
                    'mailboxId' => $mailboxId,
                    'exitCode' => $exitCode,
                    'stderr' => $errorOutput,
                ]);
            }

            unset($this->processes[$mailboxId]);

            $notBefore = $this->restartNotBefore[$mailboxId] ?? 0.0;

            if (microtime(true) < $notBefore) {
                continue;
            }

            $this->startChild($io, $mailboxId);
        }
    }

    private function startChild(SymfonyStyle $io, int $mailboxId): void
    {
        $currentBackoff = $this->backoff[$mailboxId] ?? self::MIN_BACKOFF_SECONDS;

        $process = new Process(
            ['php', 'bin/console', 'app:imap:idle', (string) $mailboxId],
            $this->projectDir,
        );
        $process->setTimeout(null);

        $process->start(function (string $type, string $buffer) use ($mailboxId): void {
            $prefix = sprintf('[mailbox %d] ', $mailboxId);
            $lines  = explode("\n", rtrim($buffer, "\n"));

            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }

                if ($type === Process::ERR) {
                    fwrite(STDERR, $prefix . $line . PHP_EOL);
                } else {
                    fwrite(STDOUT, $prefix . $line . PHP_EOL);
                }
            }
        });

        $this->processes[$mailboxId] = $process;
        $this->startedAt[$mailboxId] = microtime(true);

        $nextBackoff = $currentBackoff * 2;

        if ($nextBackoff > self::MAX_BACKOFF_SECONDS) {
            $nextBackoff = self::MAX_BACKOFF_SECONDS;
        }

        $this->backoff[$mailboxId] = $nextBackoff;
        $this->restartNotBefore[$mailboxId] = microtime(true) + $currentBackoff;

        $io->text('Started IDLE process for mailbox ' . $mailboxId . ' (pid ' . $process->getPid() . ')');
    }

    private function stopChild(int $mailboxId): void
    {
        if (array_key_exists($mailboxId, $this->processes) === false) {
            return;
        }

        $process = $this->processes[$mailboxId];
        $process->signal(SIGTERM);
        $process->wait();

        unset($this->processes[$mailboxId]);
        unset($this->startedAt[$mailboxId]);
        unset($this->backoff[$mailboxId]);
        unset($this->restartNotBefore[$mailboxId]);

        // The child was told to stop, so its row is not "stale", it is done.
        $this->heartbeats->clear(ProcessHeartbeatService::TYPE_IMAP_IDLE, (string) $mailboxId);
    }

    private function shutdownAllChildren(SymfonyStyle $io): void
    {
        foreach ($this->processes as $mailboxId => $process) {
            $io->text('Stopping mailbox ' . $mailboxId . ' (pid ' . $process->getPid() . ')');
            $process->signal(SIGTERM);
        }

        foreach ($this->processes as $mailboxId => $process) {
            $process->wait();
            $this->heartbeats->clear(ProcessHeartbeatService::TYPE_IMAP_IDLE, (string) $mailboxId);
        }

        $this->processes = [];
    }
}
