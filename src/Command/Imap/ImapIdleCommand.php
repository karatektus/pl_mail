<?php

namespace App\Command\Imap;

use App\Domain\DTO\Mail\ImapFlagNotice;
use App\Domain\Helper\ImapConnectionFactory;
use App\Infrastructure\Messaging\Message\ApplyRemoteFlagsMessage;
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

    /**
     * The shortest gap between two full mailbox syncs dispatched from here.
     *
     * THIS EXISTS BECAUSE THE LOOP FED ITSELF
     * ───────────────────────────────────────
     * Every notification used to dispatch its own SyncImapMailboxMessage, with
     * nothing anywhere checking whether one was already queued. A sync opens a
     * session and runs `UID FETCH 1:* (FLAGS)` over the whole folder, and a
     * server broadcasts flag state to an idling session when another session
     * asks for it — so the sync provoked the notification that dispatched the
     * next sync. Observed in the wild at roughly two per second, indefinitely,
     * on a 1832-message inbox: nine identical jobs in the queue at a time and a
     * sustained ten megabits a second of a machine talking to itself.
     *
     * Five seconds because it is long enough to swallow the burst a phone
     * makes when somebody reads a screenful of mail, and short enough that the
     * folder listing still feels immediate on the rare occasion it is needed.
     * With the flag path now answering directly, the listing is the rare
     * occasion.
     */
    private const SYNC_MIN_GAP = 5;

    private bool $shouldStop = false;

    /** Unix time of the last heartbeat, to throttle the idle-loop keepalive. */
    private int $lastBeatAt = 0;

    /** Unix time of the last sync dispatched from this loop. See SYNC_MIN_GAP. */
    private int $lastSyncAt = 0;

    /**
     * A sync asked for inside the quiet window and not yet sent.
     *
     * A TRAILING flush, not a dropped one, and the distinction is the whole
     * correctness of the debounce: the notification that arrives one second
     * after a sync is usually the only evidence of a change, so swallowing it
     * would lose mail state rather than merely delay it. It is held and sent
     * when the window closes — including from the read-timeout path, so a
     * mailbox that goes quiet immediately after a burst still gets its sync.
     */
    private bool $syncPending = false;

    /** Whether the held sync also needs the expensive full-folder listing. */
    private bool $sweepPending = false;

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

                    // And this is where a held sync actually leaves. The loop
                    // blocks on the socket, so a burst that ends the moment it
                    // starts — one phone, one screenful of mail — would
                    // otherwise leave the trailing dispatch sitting in a
                    // condition nothing reaches until the next notification,
                    // which on a quiet mailbox may be tomorrow.
                    $this->flushSync($mailboxId, $io);
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
                $io->text(sprintf('[%s] Notification received — sync queued.', date('H:i:s')));
                $this->wantSync($mailboxId, $io, sweep: false);
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
                $io->text(sprintf('[%s] Expunge seen — sweep queued.', date('H:i:s')));
                // A deletion genuinely needs the listing: the line carries a
                // sequence number and nothing else, and which UID has gone is
                // exactly what a listing establishes. Unchanged in kind, only
                // in cadence.
                $this->wantSync($mailboxId, $io, sweep: true);
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
            $notice = self::readFlagChange($line);

            if (null !== $notice) {
                $this->beat($mailbox, $account, true);

                // THE LINE ALREADY SAID WHAT HAPPENED, so when it named the
                // message there is nothing left to ask anybody: no connection,
                // no folder listing, no round trip. This is the branch that
                // took the traffic from ten megabits a second to nothing — the
                // old code answered this exact line by re-reading all 1832
                // UIDs to rediscover what it had just been told.
                if (true === $notice->isResolvable()) {
                    $io->text(sprintf(
                        '[%s] Flag change for UID %d — applying directly.',
                        date('H:i:s'),
                        $notice->uid,
                    ));

                    try {
                        $this->bus->dispatch(new ApplyRemoteFlagsMessage($mailboxId, $notice->uid, $notice->flags));
                    } catch (\Throwable $e) {
                        $io->error(sprintf('[%s] Flag apply dispatch failed: %s', date('H:i:s'), $e->getMessage()));
                    }
                } else {
                    // No UID, so the line named a POSITION and positions move.
                    // There is no honest way to turn one into a message here,
                    // and guessing would write somebody else's flags onto the
                    // wrong mail — so this is the case the listing exists for.
                    // Debounced like the rest; on a server that never sends
                    // UIDs this is the old behaviour with a five-second floor
                    // under it instead of none.
                    $io->text(sprintf('[%s] Flag change without a UID — refresh queued.', date('H:i:s')));
                    $this->wantSync($mailboxId, $io, sweep: true);
                }
            }
        }
    }

    /**
     * Whether a line the server pushed is announcing that flags changed.
     *
     * Kept as the question it always was, answered now by reading the line
     * rather than by sniffing it for two words. See {@see readFlagChange()}.
     */
    public static function announcesFlagChange(string $line): bool
    {
        return null !== self::readFlagChange($line);
    }

    /**
     * The same line, READ rather than recognised.
     *
     * `* 12 FETCH (UID 5001 FLAGS (\Seen \Flagged))` is the shape, and it
     * already contains the entire answer: which message, and its complete new
     * flag set. This used to be `str_contains($line, 'FETCH') &&
     * str_contains($line, 'FLAGS')` — enough to know that SOMETHING changed,
     * and nothing else, so the only available response was to go and list the
     * whole folder to find out what. On a 1832-message inbox that is one full
     * `UID FETCH 1:* (FLAGS)` per message somebody marked read on their phone,
     * and it is how an idle installation came to hold a sustained ten megabits
     * a second against its own mailbox.
     *
     * WHAT THE REGEX REFUSES, AND WHY EACH ONE MATTERS
     * ───────────────────────────────────────────────
     * The sequence number is REQUIRED, which is what excludes `* FLAGS (…)` and
     * `* OK [PERMANENTFLAGS (…)]` — the two lines every SELECT answers with.
     * Those describe what the folder *permits* rather than what any message
     * now has, and the substring test only excluded them by accident of not
     * containing "FETCH".
     *
     * FLAGS is required too. An untagged FETCH can carry other data items, and
     * only this one is worth acting on.
     *
     * An EMPTY flag list is a real notification and is kept: `FLAGS ()` means
     * every flag was cleared, which is exactly the "marked unread elsewhere"
     * case that most wants to arrive quickly.
     *
     * Static and public so that which lines count, and what they are read as,
     * is stated behaviour with a test on it rather than a condition buried in a
     * socket loop no test can reach.
     */
    public static function readFlagChange(string $line): ?ImapFlagNotice
    {
        if (1 !== preg_match('~^\*\s+(\d+)\s+FETCH\s+\((.*)\)\s*$~i', trim($line), $announcement)) {
            return null;
        }

        if (1 !== preg_match('~\bFLAGS\s+\(([^)]*)\)~i', $announcement[2], $flags)) {
            return null;
        }

        // Optional by RFC 3501 and required by RFC 9051, so present on most
        // servers and absent on some. Its absence is what sends the caller back
        // to the folder listing — see ImapFlagNotice.
        $uid = null;

        if (1 === preg_match('~\bUID\s+(\d+)~i', $announcement[2], $identified)) {
            $uid = (int) $identified[1];
        }

        return new ImapFlagNotice(
            sequence: (int) $announcement[1],
            uid:      $uid,
            flags:    array_values(array_filter(
                preg_split('~\s+~', trim($flags[1])) ?: [],
                static fn (string $flag): bool => '' !== $flag,
            )),
        );
    }

    /**
     * Ask for a sync, and send it only if one has not just gone.
     *
     * The leading edge of the debounce: the first notification after a quiet
     * spell dispatches immediately, so nothing about how fast new mail appears
     * changes. Everything inside the window is coalesced into the single held
     * request that {@see flushSync()} sends when the window closes.
     *
     * $sweep is sticky on purpose. The listing is the expensive half, and a
     * window containing one expunge and four other notifications needs it once
     * — but it does need it, so an ordinary notification arriving afterwards
     * must not clear the flag an expunge set.
     */
    private function wantSync(int $mailboxId, SymfonyStyle $io, bool $sweep): void
    {
        $this->syncPending  = true;
        $this->sweepPending = $this->sweepPending || $sweep;

        $this->flushSync($mailboxId, $io);
    }

    /**
     * Send the held sync, if there is one and the window has closed.
     *
     * markSweepDue() moved here from the notification branches, and that is
     * half the saving. It backdates the sweep clock so the next sync performs
     * the full `UID FETCH 1:* (FLAGS)` listing rather than skipping it on its
     * quarter-hour cadence — which is right once per burst and was being done
     * once per line, turning the cheapest possible trigger into the most
     * expensive possible response.
     */
    private function flushSync(int $mailboxId, SymfonyStyle $io): void
    {
        if (false === $this->syncPending) {
            return;
        }

        if (time() - $this->lastSyncAt < self::SYNC_MIN_GAP) {
            return;
        }

        $sweep = $this->sweepPending;

        // Cleared BEFORE the dispatch, not after. A throw below must not leave
        // this loop believing a sync is still owed forever, re-dispatching on
        // every subsequent line — which is the shape of the bug being fixed.
        $this->syncPending  = false;
        $this->sweepPending = false;
        $this->lastSyncAt   = time();

        try {
            if (true === $sweep) {
                $this->mailboxRepository->markSweepDue($mailboxId);
            }

            $this->bus->dispatch(new SyncImapMailboxMessage($mailboxId));
            $io->text(sprintf('[%s] Sync dispatched%s.', date('H:i:s'), $sweep ? ' with a full listing' : ''));
        } catch (\Throwable $e) {
            $io->error(sprintf('[%s] Dispatch failed: %s', date('H:i:s'), $e->getMessage()));
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
