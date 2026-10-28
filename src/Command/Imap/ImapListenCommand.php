<?php

declare(strict_types=1);

namespace App\Command\Imap;

use App\Service\Imap\ImapIdleListener;
use App\Service\Monitoring\WorkerRestartSignal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Holds IMAP IDLE on every folder that has it on, in this one process.
 *
 * Run as a child of app:imap:supervise, which starts it while there is a
 * folder to listen on and restarts it when it dies — the same arrangement as
 * app:jmap:listen. The work is ImapIdleListener's; this is the loop around it:
 * the folder set read again every RECONCILE_SECONDS, an administrator's
 * restart honoured, and a clean stop on SIGTERM.
 */
#[AsCommand(
    name: 'app:imap:listen',
    description: 'Holds IMAP IDLE on every IDLE-enabled folder in one process and queues the syncs each change asks for',
)]
final class ImapListenCommand extends Command
{
    /** Seconds between readings of which folders to listen on. */
    private const int RECONCILE_SECONDS = 60;

    /**
     * Seconds the folders run between looks at the stop flag. A signal does
     * interrupt the wait, so this is a ceiling, not the usual delay.
     */
    private const float RUN_SECONDS = 1.0;

    private bool $shouldStop = false;

    public function __construct(
        private readonly ImapIdleListener    $listener,
        private readonly WorkerRestartSignal $restartSignal,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->registerSignalHandlers();

        $startedAt     = microtime(true);
        $nextReconcile = 0.0;
        $listening     = -1;

        while (false === $this->shouldStop) {
            if (microtime(true) >= $nextReconcile) {
                if (true === $this->restartSignal->isRequestedSince($startedAt)) {
                    $io->text('Restart requested by an administrator, shutting down…');

                    break;
                }

                $this->listener->reconcile();
                $nextReconcile = microtime(true) + self::RECONCILE_SECONDS;
            }

            $this->listener->run(self::RUN_SECONDS);

            if ($listening !== $this->listener->activeCount()) {
                $listening = $this->listener->activeCount();
                $io->text(sprintf('Listening on %d folder(s)', $listening));
            }
        }

        $this->listener->stop();

        return Command::SUCCESS;
    }

    private function registerSignalHandlers(): void
    {
        if (false === function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $handler = function (): void {
            $this->shouldStop = true;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }
}
