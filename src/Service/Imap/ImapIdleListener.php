<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\DTO\Mail\ImapFlagNotice;
use App\Domain\Enum\Mail\SyncTrigger;
use App\Infrastructure\ImapClient\Exception\ImapAuthenticationException;
use App\Infrastructure\ImapClient\Exception\ImapConnectionException;
use App\Infrastructure\ImapClient\Exception\ImapException;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Infrastructure\ImapClient\ImapResponse;
use App\Infrastructure\Messaging\Message\ApplyRemoteFlagsMessage;
use App\Infrastructure\Messaging\Message\SyncImapMailboxMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MailboxRepository;
use App\Service\Monitoring\ProcessHeartbeatService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Holds IMAP IDLE on every folder that has it switched on, in one process, and
 * queues the work each notification asks for.
 *
 * WHY ONE PROCESS
 * ───────────────
 * IDLE used to be a process per folder (app:imap:idle), each a whole Symfony
 * console of about 40 MB, waiting on one socket. With the Inbox and Junk of
 * every IMAP account on IDLE, fifteen accounts came to more than a gigabyte
 * spent waiting for mail (#202, #421). Here every folder is a task on one
 * ImapLoop, and waiting costs a socket and a few kilobytes.
 *
 * The price of one process is that nothing may block it, and that is what the
 * IMAP client underneath is for: connecting, TLS, login, SELECT and IDLE all
 * wait through the loop, so a server that is slow, or that accepts a
 * connection and says nothing, holds up only its own folder. See ImapLoop for
 * the two steps that still block, neither of which is the IMAP server.
 *
 * WHAT A NOTIFICATION DOES
 * ────────────────────────
 * The same as it did in app:imap:idle, which this replaces:
 *
 *  - EXISTS queues the folder's sync.
 *  - EXPUNGE queues it with the full listing, since a deletion is found by
 *    listing.
 *  - FETCH with FLAGS and a UID applies the flags directly; without a UID it
 *    falls back to the listing.
 *  - `[ALERT]` is kept on the account for the health page (RFC 9051 §7.1
 *    says the user must be shown it).
 *
 * Syncs are debounced per folder, see SYNC_MIN_GAP.
 *
 * WHEN A CONNECTION ENDS
 * ──────────────────────
 * Long-held connections get dropped, and some servers end IDLE sessions after
 * a few minutes as a matter of course (Lark, QQ). A connection that had held
 * for HEALTHY_SECONDS is replaced at once and logged at info. One that fails
 * sooner, or never opens, is retried with a backoff of its own — other folders
 * are not touched — and logged as a warning.
 */
final class ImapIdleListener
{
    /**
     * How long IDLE runs before it is ended and started again on the same
     * connection. RFC 2177 asks for less than 30 minutes, since a server may
     * drop an IDLE that runs longer. The DONE is also the liveness check: a
     * connection that died quietly does not answer it, and is replaced.
     */
    public const int IDLE_REISSUE_SECONDS = 29 * 60;

    /**
     * How long one wait on a folder lasts before its loop looks up — to send
     * a held sync, to beat, to notice it has been asked to stop.
     */
    public const float WAIT_SECONDS = 5.0;

    /**
     * How long a connection has to have held for its loss to be ordinary.
     *
     * A connection lost within seconds of being made is not a server's
     * housekeeping: it is a server that accepts IDLE and then hangs up, or a
     * login being throttled, and reconnecting at once to that would be a
     * loop of its own.
     */
    public const int HEALTHY_SECONDS = 60;

    public const int MIN_BACKOFF_SECONDS = 5;

    public const int MAX_BACKOFF_SECONDS = 300;

    /**
     * How long a server without IDLE waits before it is asked again. Its
     * folders are still synced on the schedule; asking every few minutes
     * would only fill the log.
     */
    public const int NO_IDLE_RETRY_SECONDS = 3600;

    /**
     * The shortest gap between two syncs dispatched for one folder.
     *
     * THIS EXISTS BECAUSE THE LOOP FED ITSELF
     * ───────────────────────────────────────
     * Every notification used to dispatch its own SyncImapMailboxMessage. A
     * sync runs `UID FETCH 1:* (FLAGS)` over the folder, and a server tells an
     * idling session about flag state another session asks for — so the sync
     * provoked the notification that dispatched the next sync, at about two a
     * second on a 1832-message inbox, indefinitely.
     *
     * The first notification after a quiet spell still goes out at once; the
     * rest of the window is held and sent once when it closes. A trailing
     * flush, not a dropped one: the notification a second after a sync is
     * often the only evidence of a change.
     */
    public const int SYNC_MIN_GAP = 5;

    /** Minimum seconds between keepalive heartbeats of one folder. */
    private const int HEARTBEAT_MIN_GAP = 30;

    /** How long a timed-out connection may take to close, in stop(). */
    private const float STOP_GRACE_SECONDS = 10.0;

    /** @var array<int, ImapIdleWatch> by mailbox id */
    private array $watches = [];

    public function __construct(
        private readonly ImapLoop                $loop,
        private readonly ImapConnector           $connector,
        private readonly MailboxRepository       $mailboxes,
        private readonly AccountRepository       $accounts,
        private readonly EntityManagerInterface  $em,
        private readonly MessageBusInterface     $bus,
        private readonly ProcessHeartbeatService $heartbeats,
        private readonly LoggerInterface         $logger,
    ) {
    }

    /**
     * Start watching the folders that should be watched, and stop watching
     * the ones that should not.
     */
    public function reconcile(): void
    {
        // A long-running process: what the entity manager remembers from the
        // last reading is stale by now, credentials included.
        $this->em->clear();

        $wanted = [];

        foreach ($this->mailboxes->findIdleEnabledAndSyncEnabled() as $mailbox) {
            $wanted[(int) $mailbox->id] = true;
        }

        // A folder let go below clears its own heartbeat, but not if this
        // process was killed hard; the rows of folders that are gone are
        // cleared here as well. A wanted folder whose connection is down keeps
        // its row, which goes stale on the dashboard — that is the point of it.
        $this->heartbeats->clearOrphans(
            ProcessHeartbeatService::TYPE_IMAP_IDLE,
            array_map(strval(...), array_keys($wanted)),
        );

        foreach ($this->watches as $mailboxId => $watch) {
            if (false === isset($wanted[$mailboxId])) {
                $watch->stopRequested = true;

                if (false === $watch->isRunning()) {
                    $this->forget($watch);
                }
            }
        }

        $now = microtime(true);

        foreach (array_keys($wanted) as $mailboxId) {
            $watch = $this->watches[$mailboxId] ??= new ImapIdleWatch($mailboxId, self::MIN_BACKOFF_SECONDS);

            if (false === $watch->isRunning() && false === $watch->stopRequested && $now >= $watch->retryAt) {
                $watch->task = $this->loop->async(fn () => $this->watch($watch));
            }
        }
    }

    /** Let the folders' tasks run for up to $seconds. */
    public function run(float $seconds): void
    {
        if (false === $this->loop->isBusy()) {
            usleep((int) ($seconds * 1_000_000));
        }

        $this->loop->run($seconds);

        foreach ($this->watches as $watch) {
            if (true === $watch->stopRequested && false === $watch->isRunning()) {
                $this->forget($watch);
            }
        }
    }

    /**
     * End every IDLE and log out, and close whatever has not managed to by
     * the end of the grace period.
     */
    public function stop(float $graceSeconds = self::STOP_GRACE_SECONDS): void
    {
        foreach ($this->watches as $watch) {
            $watch->stopRequested = true;
        }

        $until = microtime(true) + $graceSeconds;

        while (microtime(true) < $until && [] !== array_filter($this->watches, static fn (ImapIdleWatch $watch): bool => $watch->isRunning())) {
            $this->loop->tick(0.25);
        }

        // A server that did not answer DONE or LOGOUT in time: closing the
        // socket wakes the task waiting on it, which then finds it closed.
        foreach ($this->watches as $watch) {
            $watch->connection?->close();
        }

        $this->loop->run(0.5);

        foreach ($this->watches as $watch) {
            $this->forget($watch);
        }
    }

    /** How many folders have a connection being held or made. */
    public function activeCount(): int
    {
        return count(array_filter($this->watches, static fn (ImapIdleWatch $watch): bool => $watch->isRunning()));
    }

    /**
     * The flag change a FETCH announces, if it is one.
     *
     * `* 12 FETCH (UID 5001 FLAGS (\Seen \Flagged))` already says which
     * message and its complete new flags, so nothing needs asking. Requires
     * the sequence number, which is what tells it apart from the `* FLAGS`
     * and `[PERMANENTFLAGS]` every SELECT answers with: those say what the
     * folder permits, not what any message has. An empty list is kept — it
     * means every flag was cleared, "marked unread elsewhere".
     *
     * The UID is optional in RFC 3501 and required in RFC 9051; without it
     * the change names a position, positions move, and the caller has to go
     * to the listing instead — see ImapFlagNotice::isResolvable().
     */
    public static function flagChange(ImapResponse $response): ?ImapFlagNotice
    {
        if (null === $response->number) {
            return null;
        }

        $items = $response->fetchItems();

        if (false === is_array($items['FLAGS'] ?? null)) {
            return null;
        }

        $uid = $items['UID'] ?? null;

        return new ImapFlagNotice(
            sequence: $response->number,
            uid:      true === is_string($uid) && 1 === preg_match('~^\d+$~', $uid) ? (int) $uid : null,
            flags:    array_values(array_filter($items['FLAGS'], is_string(...))),
        );
    }

    // ---------------------------------------------------------------- one folder

    /**
     * One connection's life for one folder, and what to do after it.
     *
     * Never throws: this runs as a task on the loop every folder shares, and
     * how one folder's connection ended is that folder's business.
     */
    private function watch(ImapIdleWatch $watch): void
    {
        $openedAt = microtime(true);

        try {
            $this->hold($watch);
        } catch (\Throwable $e) {
            $this->ended($watch, $e, microtime(true) - $openedAt);
        } finally {
            $watch->connection?->close();
            $watch->connection = null;

            // A held sync has to leave: the notification behind it may be the
            // last thing that connection said.
            $this->flushSync($watch, true);
        }
    }

    private function hold(ImapIdleWatch $watch): void
    {
        $mailbox = $this->mailboxes->find($watch->mailboxId);

        if (null === $mailbox || null === $mailbox->account) {
            $watch->stopRequested = true;

            return;
        }

        $account          = $mailbox->account;
        $watch->path      = (string) $mailbox->fullPath;
        $watch->account   = (string) $account->email;
        $watch->accountId = $account->id;

        $connection        = $this->connector->open($this->loop, $account);
        $watch->connection = $connection;

        if (false === $connection->supports('IDLE')) {
            $watch->retryAt = microtime(true) + self::NO_IDLE_RETRY_SECONDS;
            $this->logger->info('ImapIdleListener: the server does not support IDLE', [
                'mailboxId' => $watch->mailboxId,
                'account'   => $watch->account,
            ]);
            $connection->logout();

            return;
        }

        $selected = $connection->select($watch->path);
        $this->handle($watch, [...$selected->untagged, $selected->completion]);

        $this->beat($watch, true);
        $this->logger->debug('ImapIdleListener: idling', ['mailboxId' => $watch->mailboxId, 'account' => $watch->account]);

        $heldSince = microtime(true);

        while (false === $watch->stopRequested) {
            $idle      = $connection->idle();
            $reissueAt = microtime(true) + self::IDLE_REISSUE_SECONDS;

            while (false === $watch->stopRequested && false === $idle->hasEnded && microtime(true) < $reissueAt) {
                $this->handle($watch, $idle->wait(min(self::WAIT_SECONDS, $reissueAt - microtime(true))));
                $this->flushSync($watch);
                $this->beat($watch);
            }

            $this->handle($watch, $idle->done());

            // A connection that has held this long is a working one: the next
            // failure starts its backoff from the bottom again.
            if (microtime(true) - $heldSince >= self::HEALTHY_SECONDS) {
                $watch->backoff = self::MIN_BACKOFF_SECONDS;
            }
        }

        $connection->logout();
    }

    /**
     * @param list<ImapResponse> $responses
     */
    private function handle(ImapIdleWatch $watch, array $responses): void
    {
        foreach ($responses as $response) {
            if ('ALERT' === $response->code) {
                $this->recordAlert($watch, $response->text);
            }

            if ('EXISTS' === $response->keyword) {
                $this->beat($watch, true);
                $this->wantSync($watch, false);

                continue;
            }

            // The sequence number of the message that went is not resolved
            // here: the deletion sweep is the authority, with its safety
            // rails, and this only makes it run now instead of on its cadence.
            if ('EXPUNGE' === $response->keyword) {
                $this->beat($watch, true);
                $this->wantSync($watch, true);

                continue;
            }

            $notice = self::flagChange($response);

            if (null === $notice) {
                continue;
            }

            $this->beat($watch, true);

            if (false === $notice->isResolvable()) {
                $this->wantSync($watch, true);

                continue;
            }

            try {
                $this->bus->dispatch(new ApplyRemoteFlagsMessage($watch->mailboxId, (int) $notice->uid, $notice->flags));
            } catch (\Throwable $e) {
                $this->logger->error('ImapIdleListener: a flag change could not be queued', [
                    'mailboxId' => $watch->mailboxId,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Decide what comes after a connection that ended with $e.
     */
    private function ended(ImapIdleWatch $watch, \Throwable $e, float $heldSeconds): void
    {
        if (true === $watch->stopRequested && $e instanceof ImapException) {
            return;
        }

        $context = [
            'mailboxId' => $watch->mailboxId,
            'account'   => $watch->account,
            'error'     => $e->getMessage(),
        ];

        if ($e instanceof ImapConnectionException && $heldSeconds >= self::HEALTHY_SECONDS) {
            $watch->backoff = self::MIN_BACKOFF_SECONDS;
            $watch->retryAt = microtime(true) + 1;

            $this->logger->info('ImapIdleListener: the server ended the connection, reconnecting', $context + [
                'heldSecs' => (int) $heldSeconds,
            ]);

            return;
        }

        $wait           = $watch->backoff;
        $watch->retryAt = microtime(true) + $wait;
        $watch->backoff = min(self::MAX_BACKOFF_SECONDS, 2 * $wait);

        if ($e instanceof ImapException) {
            $this->logger->warning(
                $e instanceof ImapAuthenticationException
                    ? 'ImapIdleListener: the server refused the login, retrying later'
                    : 'ImapIdleListener: the connection failed, retrying later',
                $context + ['retryInSecs' => $wait],
            );

            return;
        }

        // Not the server: a bug, or the database. Logged as what it is.
        $this->logger->error('ImapIdleListener: watching a folder failed, retrying later', $context + [
            'retryInSecs' => $wait,
            'exception'   => $e,
        ]);
    }

    private function forget(ImapIdleWatch $watch): void
    {
        $watch->connection?->close();

        unset($this->watches[$watch->mailboxId]);

        $this->heartbeats->clear(ProcessHeartbeatService::TYPE_IMAP_IDLE, (string) $watch->mailboxId);
    }

    // ---------------------------------------------------------------- syncs

    /**
     * Ask for a sync, and send it unless one has just gone.
     *
     * $sweep is sticky: a window with one expunge and four new messages needs
     * the listing once, and the new messages must not clear what the expunge
     * asked for.
     */
    private function wantSync(ImapIdleWatch $watch, bool $sweep): void
    {
        $watch->syncPending  = true;
        $watch->sweepPending = $watch->sweepPending || $sweep;

        $this->flushSync($watch);
    }

    /**
     * Send the held sync, if there is one and the window has closed — or
     * whatever the window, with $now.
     */
    private function flushSync(ImapIdleWatch $watch, bool $now = false): void
    {
        if (false === $watch->syncPending) {
            return;
        }

        if (false === $now && time() - $watch->lastSyncAt < self::SYNC_MIN_GAP) {
            return;
        }

        $sweep = $watch->sweepPending;

        // Cleared before the dispatch: a throw below must not leave the folder
        // owing a sync it will try to send on every line from now on.
        $watch->syncPending  = false;
        $watch->sweepPending = false;
        $watch->lastSyncAt   = time();

        try {
            // Backdating the sweep clock makes the next sync run the full
            // listing instead of skipping it until its quarter hour.
            if (true === $sweep) {
                $this->mailboxes->markSweepDue($watch->mailboxId);
            }

            $this->bus->dispatch(new SyncImapMailboxMessage($watch->mailboxId, SyncTrigger::Idle));
        } catch (\Throwable $e) {
            $this->logger->error('ImapIdleListener: a sync could not be queued', [
                'mailboxId' => $watch->mailboxId,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    // ---------------------------------------------------------------- bookkeeping

    /**
     * The folder's heartbeat, which the health page reads. Lifecycle beats
     * pass $force; the keepalive is throttled to HEARTBEAT_MIN_GAP.
     */
    private function beat(ImapIdleWatch $watch, bool $force = false): void
    {
        $now = time();

        if (false === $force && $now - $watch->lastBeatAt < self::HEARTBEAT_MIN_GAP) {
            return;
        }

        $watch->lastBeatAt = $now;

        $this->heartbeats->beat(
            ProcessHeartbeatService::TYPE_IMAP_IDLE,
            (string) $watch->mailboxId,
            ['mailbox' => $watch->path, 'account' => $watch->account],
        );
    }

    /**
     * Keep what the server said for the health page.
     *
     * Best effort: a database that is briefly away must not take the IDLE
     * connection down with it. An alert is worth recording and never worth
     * missing mail for.
     */
    private function recordAlert(ImapIdleWatch $watch, string $text): void
    {
        $text = trim($text);

        if ('' === $text || null === $watch->accountId) {
            return;
        }

        try {
            $account = $this->accounts->find($watch->accountId);

            if (null === $account || $account->imapServerAlert === $text) {
                return;
            }

            $account->imapServerAlert = mb_substr($text, 0, 500);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('ImapIdleListener: could not record a server alert', [
                'accountId' => $watch->accountId,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
