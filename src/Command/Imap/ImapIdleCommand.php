<?php

namespace App\Command\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Infrastructure\Messaging\Message\SyncImapMailboxMessage;
use App\Entity\Mail\Account;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MailboxRepository;
use App\Service\Monitoring\ProcessHeartbeatService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
        private readonly AccountRepository       $accountRepository,
        private readonly EntityManagerInterface  $entityManager,
        private readonly LoggerInterface         $logger,
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

        if (false === $mailbox->isIdleEnabled || false === $mailbox->isSyncEnabled) {
            $io->error('Mailbox is not enabled for IDLE.');
            return Command::FAILURE;
        }

        $this->registerSignalHandlers($io);

        $io->info(sprintf(
            'Starting IDLE on mailbox "%s" for account "%s"',
            $mailbox->name,
            $mailbox->account->email,
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

        $this->heartbeats->clear(ProcessHeartbeatService::TYPE_IMAP_IDLE, (string) $mailboxId);

        $io->text(sprintf('[%s] Stopped cleanly.', date('H:i:s')));

        return Command::SUCCESS;
    }

    private function idle(int $mailboxId, SymfonyStyle $io): void
    {
        $mailbox    = $this->mailboxRepository->find($mailboxId);
        $account    = $mailbox->account;
        $client     = $this->imapConnectionFactory->connect($account);
        $folder     = $client->getFolder($mailbox->name);

        if (null === $folder) {
            $client->disconnect();
            throw new \RuntimeException(sprintf('Folder "%s" not found.', $mailbox->name));
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

            // The one line in this loop the RFC says we MUST show somebody.
            //
            // `* OK [ALERT] …` is how a server tells the USER something no
            // other part of the protocol will: the mailbox is over quota, the
            // password expires on Friday, the app password is being retired.
            // RFC 3501 §7.1 requires a client to present that text. This loop
            // read it and dropped it, so an over-quota mailbox — the commonest
            // case by far, and common in particular on the consumer ISPs in
            // plMail's own preset list — was one where mail simply stopped
            // arriving with nothing on screen to explain it.
            //
            // Recorded rather than printed: this command runs in a container
            // nobody is watching, so the console is not where a user is.
            if (true === str_contains($line, '[ALERT]')) {
                $io->warning(sprintf('[%s] Server alert: %s', date('H:i:s'), trim($line)));
                $this->recordAlert($account, $line);
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

            // The server announces every deletion to an idling client as an
            // untagged EXPUNGE — a line this loop used to read and drop.
            // WHICH message died is a sequence number this deliberately does
            // not resolve: the deletion sweep is the authority, with the
            // safety rails, and this only makes it run NOW for this folder
            // instead of on its cadence. Backdate the sweep clock and
            // dispatch the same sync EXISTS dispatches — the deletion lands
            // in seconds instead of within two fifteen-minute cycles.
            if (true === str_contains($line, 'EXPUNGE')) {
                $this->beat($mailbox, $account, true);
                $io->text(sprintf('[%s] Expunge seen — sweeping now.', date('H:i:s')));
                try {
                    $this->mailboxRepository->markSweepDue($mailboxId);
                    $this->bus->dispatch(new SyncImapMailboxMessage($mailboxId));
                } catch (\Throwable $e) {
                    $io->error(sprintf('[%s] Sweep dispatch failed: %s', date('H:i:s'), $e->getMessage()));
                }
            }

            // `* 4 FETCH (FLAGS (\Seen))` — what a server sends an idling
            // client when another client changes a message's flags. Mail read
            // on a phone announces itself here, and this loop used to drop the
            // line exactly as it once dropped EXPUNGE.
            //
            // WHICH message is a sequence number, and this deliberately does
            // not resolve it — the same trigger-versus-authority split the
            // expunge above is built on. The flag pass rides on the folder
            // listing, so it is the *same clock*: backdating sweptAt makes the
            // one `UID FETCH 1:* (FLAGS)` run now, and it answers presence and
            // flags together. A star set elsewhere lands in seconds rather than
            // within two fifteen-minute cycles.
            //
            // FLAGS as well as FETCH, because an untagged FETCH can carry other
            // data items and only this one is worth a round trip for.
            if (true === self::announcesFlagChange($line)) {
                $this->beat($mailbox, $account, true);
                $io->text(sprintf('[%s] Flag change seen — refreshing now.', date('H:i:s')));
                try {
                    $this->mailboxRepository->markSweepDue($mailboxId);
                    $this->bus->dispatch(new SyncImapMailboxMessage($mailboxId));
                } catch (\Throwable $e) {
                    $io->error(sprintf('[%s] Flag refresh dispatch failed: %s', date('H:i:s'), $e->getMessage()));
                }
            }
        }
    }

    /**
     * Whether a line the server pushed is announcing that flags changed.
     *
     * `* 4 FETCH (FLAGS (\Seen))` is the shape, and both halves are required.
     * An untagged FETCH can carry other data items, and a line mentioning
     * FLAGS without being a FETCH — the FLAGS and PERMANENTFLAGS lines every
     * SELECT answers with — describes what the folder *permits* rather than
     * what any message now has. Waking a folder listing for either would be a
     * round trip that learns nothing.
     *
     * Static and public so that which lines count is a stated behaviour with a
     * test on it rather than a condition buried in a socket loop no test can
     * reach.
     */
    public static function announcesFlagChange(string $line): bool
    {
        return true === str_contains($line, 'FETCH') && true === str_contains($line, 'FLAGS');
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
            (string) $mailbox->id,
            ['mailbox' => $mailbox->fullPath, 'account' => $account->email],
        );
    }
    /**
     * Keep what the server said, for the health page to show.
     *
     * The text is taken as sent, minus the protocol furniture: a server writes
     * `* OK [ALERT] Quota exceeded (mailbox for user is full)`, and the part
     * worth showing a person starts after the bracket.
     *
     * Best effort by design. This runs inside a socket loop whose job is to
     * stay connected, so a database that is briefly unavailable must not take
     * the IDLE connection down with it — an alert is worth recording and never
     * worth dropping mail delivery for.
     */
    private function recordAlert(?Account $account, string $line): void
    {
        if (null === $account) {
            return;
        }

        $text = trim((string) preg_replace('/^.*\[ALERT\]\s*/i', '', trim($line)));

        if ('' === $text) {
            return;
        }

        try {
            $fresh = $this->accountRepository->find($account->id);

            if (null === $fresh || $fresh->imapServerAlert === $text) {
                return;
            }

            $fresh->imapServerAlert = mb_substr($text, 0, 500);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('ImapIdleCommand: could not record a server alert', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
