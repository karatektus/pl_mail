<?php

namespace App\Command;

use App\Domain\Helper\ImapConnectionFactory;
use App\Message\SyncImapMailboxMessage;
use App\Repository\MailboxRepository;
use App\Service\Monitoring\ProcessHeartbeatService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;

#[AsCommand(
    name: 'app:imap:idle',
    description: 'Hold an IMAP IDLE connection for a single mailbox and dispatch sync messages on change',
)]
class ImapIdleCommand extends Command
{
    private const IDLE_TIMEOUT      = 1740; // 29 minutes in seconds (RFC max is 30)
    private const RECONNECT_DELAY   = 5;    // seconds between reconnection attempts
    private const MAX_RETRIES       = 10;
    private const HEARTBEAT_MIN_GAP = 30;   // min seconds between idle-loop keepalive beats

    private bool $shouldStop = false;

    /** Unix time of the last heartbeat, to throttle the idle-loop keepalive. */
    private int $lastBeatAt = 0;

    public function __construct(
        private readonly MailboxRepository       $mailboxRepository,
        private readonly MessageBusInterface     $bus,
        private readonly ImapConnectionFactory   $imapConnectionFactory,
        private readonly ProcessHeartbeatService $heartbeats,
    ) {
        parent::__construct();
    }

    private function registerSignalHandlers(SymfonyStyle $io): void
    {
        if (false === function_exists('pcntl_async_signals')) {
            $io->warning('pcntl extension not available — signal handling disabled');
            return;
        }

        pcntl_async_signals(true);

        $handler = function (int $signal) use ($io): void {
            $io->text(sprintf('[%s] Received signal %d, stopping after current IDLE.', date('H:i:s'), $signal));
            $this->shouldStop = true;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('mailbox-id', InputArgument::REQUIRED, 'ID of the mailbox to IDLE on');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $mailboxId  = (int) $input->getArgument('mailbox-id');
        $mailbox    = $this->mailboxRepository->find($mailboxId);

        if (null === $mailbox) {
            $io->error(sprintf('Mailbox %d not found.', $mailboxId));
            return Command::FAILURE;
        }

        if (false === $mailbox->isIdleEnabled() || false === $mailbox->isSyncEnabled()) {
            $io->error('Mailbox is not enabled for IDLE.');
            return Command::FAILURE;
        }

        $this->registerSignalHandlers($io);

        $io->info(sprintf(
            'Starting IDLE on mailbox "%s" for account "%s"',
            $mailbox->getName(),
            $mailbox->getAccount()->getEmail(),
        ));

        $retries = 0;

        while (false === $this->shouldStop) {
            try {
                $this->idle($mailboxId, $io);
                $retries = 0;
            } catch (\Throwable $e) {
                if (true === $this->shouldStop) {
                    break;
                }

                $retries++;
                $io->error(sprintf(
                    'IDLE connection failed (attempt %d/%d): %s',
                    $retries,
                    self::MAX_RETRIES,
                    $e->getMessage(),
                ));

                if ($retries >= self::MAX_RETRIES) {
                    $io->error('Max retries reached. Giving up.');
                    return Command::FAILURE;
                }

                $delay = self::RECONNECT_DELAY * $retries;
                $io->note(sprintf('Reconnecting in %d seconds...', $delay));
                sleep($delay);
            }
        }

        $io->text(sprintf('[%s] Stopped cleanly.', date('H:i:s')));

        return Command::SUCCESS;
    }

    private function idle(int $mailboxId, SymfonyStyle $io): void
    {
        $mailbox    = $this->mailboxRepository->find($mailboxId);
        $account    = $mailbox->getAccount();
        $client     = $this->imapConnectionFactory->connect($account);
        $folder     = $client->getFolder($mailbox->getName());

        if (null === $folder) {
            $client->disconnect();
            throw new \RuntimeException(sprintf('Folder "%s" not found.', $mailbox->getName()));
        }

        $this->beat($mailbox, $account, true);

        $connection = $client->getConnection();
        $connection->selectFolder($folder->path);
        $connection->idle();

        $io->text(sprintf('[%s] IDLE connection established.', date('H:i:s')));

        $startTime = time();

        while (true) {

            if (true === $this->shouldStop) {
                $io->text(sprintf('[%s] Shutdown requested — closing IDLE cleanly.', date('H:i:s')));
                $connection->done();
                $client->disconnect();
                return;
            }

            if (time() - $startTime >= self::IDLE_TIMEOUT) {
                $io->text(sprintf('[%s] IDLE timeout — reconnecting.', date('H:i:s')));
                $connection->done();
                $client->disconnect();
                $this->beat($mailbox, $account, true);
                return;
            }

            try {
                $line = $connection->nextLine(new Response(0, false));
            } catch (\Throwable $e) {
                if (true === $this->shouldStop) {
                    $connection->done();
                    $client->disconnect();
                    return;
                }

                if (true === str_contains($e->getMessage(), 'empty response')) {
                    // Stream read timed out — the normal quiet-mailbox case.
                    // Beat (throttled) so liveness is detected far faster than
                    // the 29-minute IDLE re-issue would allow.
                    $this->beat($mailbox, $account);
                    continue;
                }
                throw $e;
            }

            if (true === str_contains($line, 'EXISTS')) {
                $this->beat($mailbox, $account, true);
                $io->text(sprintf('[%s] Notification received — dispatching sync.', date('H:i:s')));
                try {
                    $envelope = $this->bus->dispatch(new SyncImapMailboxMessage($mailboxId));
                    $io->text(sprintf('[%s] Dispatch returned envelope.', date('H:i:s')));
                } catch (\Throwable $e) {
                    $io->error(sprintf('[%s] Dispatch failed: %s', date('H:i:s'), $e->getMessage()));
                }
            }
        }
    }

    /**
     * Record a liveness heartbeat for this mailbox's IDLE process. Lifecycle
     * beats (connect, timeout re-issue, EXISTS wake) pass $force so they
     * always land; the idle-loop keepalive is throttled to HEARTBEAT_MIN_GAP.
     */
    private function beat(object $mailbox, object $account, bool $force = false): void
    {
        $now = time();

        if (false === $force && ($now - $this->lastBeatAt) < self::HEARTBEAT_MIN_GAP) {
            return;
        }

        $this->lastBeatAt = $now;

        $this->heartbeats->beat(
            ProcessHeartbeatService::TYPE_IMAP_IDLE,
            (string) $mailbox->getId(),
            ['mailbox' => $mailbox->getFullPath(), 'account' => $account->getEmail()],
        );
    }
}
