<?php

declare(strict_types=1);

namespace App\Command\Imap;

use App\Repository\Mail\AccountRepository;
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

/**
 * Keeps the two push listeners running: app:imap:listen, which holds IMAP IDLE
 * on every folder that has it on, and app:jmap:listen, which holds a JMAP
 * stream for every JMAP account.
 *
 * Each runs while there is something for it to listen to, and is restarted
 * with a backoff when it dies. It used to be one app:imap:idle child per
 * folder, a whole Symfony console each; the folders now share one process
 * (#421), and the name of this command, and of the imap-supervisor that
 * app:work runs it as, is left from then.
 */
#[AsCommand(
    name: 'app:imap:supervise',
    description: 'Supervises the IMAP IDLE listener and the JMAP push listener',
)]
final class ImapSuperviseCommand extends Command
{
    private const int DEFAULT_POLL_INTERVAL = 60;
    private const int MIN_BACKOFF_SECONDS = 1;
    private const int MAX_BACKOFF_SECONDS = 30;
    private const int BACKOFF_RESET_AFTER_UPTIME_SECONDS = 120;

    private const string IMAP_LISTENER = 'imap';

    private const string JMAP_LISTENER = 'jmap';

    /** @var array<string, Process> by listener */
    private array $processes = [];

    /** @var array<string, int> listener => current backoff seconds */
    private array $backoff = [];

    /** @var array<string, float> listener => microtime the process was (re)started */
    private array $startedAt = [];

    /** @var array<string, float> listener => microtime we're allowed to restart at, after a failure */
    private array $restartNotBefore = [];

    private bool $shouldStop = false;

    public function __construct(
        private readonly MailboxRepository $mailboxRepository,
        private readonly AccountRepository $accountRepository,
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
            'Seconds between looks at whether each listener has anything to listen to',
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
        $idleMailboxIds = array_map(
            static fn ($mailbox): int => (int) $mailbox->id,
            $this->mailboxRepository->findIdleEnabledAndSyncEnabled(),
        );

        $wanted = [
            self::IMAP_LISTENER => [] !== $idleMailboxIds,
            self::JMAP_LISTENER => [] !== $this->accountRepository->findJmapForPush(),
        ];

        foreach ($wanted as $listener => $isWanted) {
            if (true === $isWanted && false === array_key_exists($listener, $this->processes)) {
                $this->startChild($io, $listener);
            }

            if (false === $isWanted && true === array_key_exists($listener, $this->processes)) {
                $io->text(self::JMAP_LISTENER === $listener
                    ? 'No JMAP account to listen for, stopping the listener'
                    : 'No folder has IDLE on, stopping the IMAP listener');
                $this->stopChild($listener);
            }
        }

        $this->reapHeartbeats($io, $idleMailboxIds);
    }

    /**
     * The listener clears the heartbeat of a folder it stops watching, but not
     * if it is killed hard, so the rows of folders that are gone are cleared
     * here too. Rows for still-active folders are left alone even when the
     * listener is down — those should show up stale on the dashboard, not
     * silently vanish.
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
        foreach ($this->processes as $listener => $process) {
            if ($process->isRunning() === true) {
                $uptime = microtime(true) - $this->startedAt[$listener];

                if ($uptime >= self::BACKOFF_RESET_AFTER_UPTIME_SECONDS) {
                    $this->backoff[$listener] = self::MIN_BACKOFF_SECONDS;
                }

                continue;
            }

            $exitCode = $process->getExitCode();
            $errorOutput = $process->getErrorOutput();

            $io->warning(sprintf('The %s listener died (exit code %s)', $listener, $exitCode));

            if ($errorOutput !== '') {
                $this->logger->error(sprintf('The %s listener wrote to stderr', $listener), [
                    'listener' => $listener,
                    'exitCode' => $exitCode,
                    'stderr' => $errorOutput,
                ]);
            }

            unset($this->processes[$listener]);

            $notBefore = $this->restartNotBefore[$listener] ?? 0.0;

            if (microtime(true) < $notBefore) {
                continue;
            }

            $this->startChild($io, $listener);
        }
    }

    private function startChild(SymfonyStyle $io, string $listener): void
    {
        $currentBackoff = $this->backoff[$listener] ?? self::MIN_BACKOFF_SECONDS;

        $command = self::JMAP_LISTENER === $listener
            ? ['php', 'bin/console', 'app:jmap:listen']
            : ['php', 'bin/console', 'app:imap:listen'];

        $process = new Process($command, $this->projectDir);
        $process->setTimeout(null);

        $process->start(function (string $type, string $buffer) use ($listener): void {
            $prefix = sprintf('[%s] ', $listener);
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

        $this->processes[$listener] = $process;
        $this->startedAt[$listener] = microtime(true);

        $nextBackoff = $currentBackoff * 2;

        if ($nextBackoff > self::MAX_BACKOFF_SECONDS) {
            $nextBackoff = self::MAX_BACKOFF_SECONDS;
        }

        $this->backoff[$listener] = $nextBackoff;
        $this->restartNotBefore[$listener] = microtime(true) + $currentBackoff;

        $io->text(sprintf('Started the %s listener (pid %d)', $listener, $process->getPid()));
    }

    private function stopChild(string $listener): void
    {
        if (array_key_exists($listener, $this->processes) === false) {
            return;
        }

        $process = $this->processes[$listener];
        $process->signal(SIGTERM);
        $process->wait();

        unset($this->processes[$listener]);
        unset($this->startedAt[$listener]);
        unset($this->backoff[$listener]);
        unset($this->restartNotBefore[$listener]);

        // The child was told to stop, so its row is not "stale", it is done.
        $this->clearHeartbeat($listener);
    }

    private function shutdownAllChildren(SymfonyStyle $io): void
    {
        foreach ($this->processes as $listener => $process) {
            $io->text(sprintf('Stopping the %s listener (pid %d)', $listener, $process->getPid()));
            $process->signal(SIGTERM);
        }

        foreach ($this->processes as $listener => $process) {
            $process->wait();
            $this->clearHeartbeat($listener);
        }

        $this->processes = [];
    }

    /**
     * The IMAP listener clears its folders' rows itself on a clean stop; the
     * JMAP listener's one row is cleared here in case it could not.
     */
    private function clearHeartbeat(string $listener): void
    {
        if (self::JMAP_LISTENER === $listener) {
            $this->heartbeats->clear(ProcessHeartbeatService::TYPE_JMAP_LISTEN, 'main');
        }
    }
}
