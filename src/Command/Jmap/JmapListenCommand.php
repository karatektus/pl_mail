<?php

declare(strict_types=1);

namespace App\Command\Jmap;

use App\Domain\Exception\JmapApiException;
use App\Entity\Mail\Account;
use App\Infrastructure\JmapClient\JmapCapability;
use App\Infrastructure\JmapClient\JmapClient;
use App\Infrastructure\JmapClient\JmapEventStream;
use App\Repository\Mail\AccountRepository;
use App\Service\JmapClient\JmapPushFanout;
use App\Service\Monitoring\ProcessHeartbeatService;
use App\Service\Monitoring\WorkerRestartSignal;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Holds a JMAP EventSource stream open for every JMAP account, and queues the
 * syncs each change asks for.
 *
 * The JMAP counterpart of app:imap:idle, and run the same way: as a child of
 * app:imap:supervise, which starts it when there is a JMAP account to listen
 * for and restarts it with backoff when it dies. So it needs no container of
 * its own and no deployment change — the supervisor every install already runs
 * is what keeps it alive.
 *
 * ## One process, every account
 *
 * Unlike IDLE, which holds a socket per folder and so a process per folder, a
 * JMAP stream is one HTTP response per account that the server writes to when
 * anything changes. Symfony's HttpClient waits on many responses at once, so
 * one process holds them all and wakes when any of them speaks.
 *
 * ## What a change does
 *
 * Nothing here reads a change. A StateChange names types and states, and
 * JmapPushFanout turns it into the ordinary sync of that kind — the same
 * SyncAccountMessage a poll queues, marked as a push. The server sends one
 * as soon as a stream opens, so every connect is also a catch-up.
 *
 * ## Which accounts
 *
 * Active JMAP accounts with push on, less the ones whose server is pushing to
 * plMail's webhook already — see Account::hasLiveJmapPushSubscription(). The
 * set is read again every RECONCILE_SECONDS, so an account added, disabled or
 * moved to the webhook is picked up without a restart.
 */
#[AsCommand(
    name: 'app:jmap:listen',
    description: 'Holds a JMAP push stream open for every JMAP account and queues the syncs each change asks for',
)]
final class JmapListenCommand extends Command
{
    /** Seconds between readings of which accounts to listen for. */
    private const int RECONCILE_SECONDS = 60;

    /** Seconds the server is asked to leave between pings on an idle stream. */
    private const int PING_SECONDS = 60;

    /**
     * Seconds a stream may say nothing before it counts as dead.
     *
     * Three pings' worth: a server that missed two is not pinging, and a
     * connection that a NAT box dropped silently says nothing at all.
     */
    private const int IDLE_TIMEOUT_SECONDS = 3 * self::PING_SECONDS;

    /** Seconds one wait on the streams lasts before the loop looks up. */
    private const float WAIT_SECONDS = 5.0;

    /** Seconds between heartbeats. Matches the Messenger listener's. */
    private const int BEAT_SECONDS = 30;

    private const int MIN_BACKOFF_SECONDS = 5;
    private const int MAX_BACKOFF_SECONDS = 300;

    /** @var array<int, array{response: ResponseInterface, parser: JmapEventStream, accountIds: list<string>}> */
    private array $streams = [];

    /** @var array<int, int> account id → seconds to wait after the next failure */
    private array $backoff = [];

    /** @var array<int, float> account id → microtime before which it is not reopened */
    private array $retryAt = [];

    private bool $shouldStop = false;

    public function __construct(
        private readonly AccountRepository       $accounts,
        private readonly JmapClient              $jmap,
        private readonly JmapPushFanout          $fanout,
        private readonly EntityManagerInterface  $em,
        private readonly ProcessHeartbeatService $heartbeats,
        private readonly WorkerRestartSignal     $restartSignal,
        private readonly LoggerInterface         $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->registerSignalHandlers();

        $startedAt     = microtime(true);
        $nextReconcile = 0.0;
        $nextBeat      = 0.0;

        while (false === $this->shouldStop) {
            $now = microtime(true);

            if ($now >= $nextReconcile) {
                if (true === $this->restartSignal->isRequestedSince($startedAt)) {
                    $io->text('Restart requested by an administrator, shutting down…');

                    break;
                }

                $this->reconcile($io);
                $nextReconcile = $now + self::RECONCILE_SECONDS;
            }

            if ($now >= $nextBeat) {
                $this->heartbeats->beat(ProcessHeartbeatService::TYPE_JMAP_LISTEN, 'main', ['streams' => count($this->streams)]);
                $nextBeat = $now + self::BEAT_SECONDS;
            }

            if ([] === $this->streams) {
                $this->sleep(self::WAIT_SECONDS);

                continue;
            }

            $this->wait();
        }

        foreach (array_keys($this->streams) as $accountId) {
            $this->close($accountId, false);
        }

        $this->heartbeats->clear(ProcessHeartbeatService::TYPE_JMAP_LISTEN, 'main');

        return Command::SUCCESS;
    }

    /**
     * Open the streams that should be open, and close the ones that should not.
     */
    private function reconcile(SymfonyStyle $io): void
    {
        // A long-running process: what the entity manager remembers from the
        // last reading is stale by now, credentials included.
        $this->em->clear();

        $wanted = [];

        foreach ($this->accounts->findJmapForPush() as $account) {
            if (true === $account->hasLiveJmapPushSubscription()) {
                continue;
            }

            $wanted[(int) $account->id] = $account;
        }

        foreach (array_keys($this->streams) as $accountId) {
            if (false === isset($wanted[$accountId])) {
                $io->text(sprintf('Account %d no longer wants a stream, closing it', $accountId));
                $this->close($accountId, false);
            }
        }

        $now = microtime(true);

        foreach ($wanted as $accountId => $account) {
            if (false === isset($this->streams[$accountId]) && $now >= ($this->retryAt[$accountId] ?? 0.0)) {
                $this->open($io, $account);
            }
        }
    }

    private function open(SymfonyStyle $io, Account $account): void
    {
        $accountId = (int) $account->id;

        try {
            $this->jmap->forget($account);
            $session = $this->jmap->session($account);

            if (null === $session->eventSourceUrl) {
                // Not a failure to retry soon: the server offers no stream,
                // and asking again in five seconds would not change that.
                $this->retryAt[$accountId] = microtime(true) + self::MAX_BACKOFF_SECONDS;
                $this->logger->info('JmapListen: the server offers no EventSource stream', ['accountId' => $accountId]);

                return;
            }

            $url = strtr($session->eventSourceUrl, [
                '{types}'      => '*',
                '{closeafter}' => 'no',
                '{ping}'       => (string) self::PING_SECONDS,
            ]);

            $response = $this->jmap->open($account, $url, [
                'headers'      => ['Accept' => 'text/event-stream'],
                'timeout'      => self::IDLE_TIMEOUT_SECONDS,
                'max_duration' => 0,
                'buffer'       => false,
            ]);

            $accountIds = array_values(array_unique(array_filter([
                $session->accountFor(JmapCapability::MAIL),
                $session->accountFor(JmapCapability::CALENDARS),
                $session->accountFor(JmapCapability::CONTACTS),
            ])));
        } catch (JmapApiException|HttpClientException $e) {
            $this->failed($accountId, $e);

            return;
        }

        $this->streams[$accountId] = ['response' => $response, 'parser' => new JmapEventStream(), 'accountIds' => $accountIds];

        $io->text(sprintf('Listening for account %d', $accountId));
    }

    /**
     * Wait on every stream at once, and act on what any of them said.
     *
     * Returns at the first timeout: the loop around it has housekeeping to do
     * — the heartbeat, the account set, a signal to stop — and a wait that
     * only ended when a server spoke could last as long as nobody wrote mail.
     */
    private function wait(): void
    {
        $byResponse = new \SplObjectStorage();

        foreach ($this->streams as $accountId => $stream) {
            $byResponse[$stream['response']] = $accountId;
        }

        try {
            foreach ($this->jmap->stream(array_column($this->streams, 'response'), self::WAIT_SECONDS) as $response => $chunk) {
                $accountId = $byResponse[$response] ?? null;

                if (null === $accountId || false === isset($this->streams[$accountId])) {
                    continue;
                }

                try {
                    if (true === $chunk->isTimeout()) {
                        return;
                    }

                    if (true === $chunk->isFirst() && 200 !== $response->getStatusCode()) {
                        $this->failed($accountId, new JmapApiException(sprintf('EventSource answered %d.', $response->getStatusCode()), $response->getStatusCode()));

                        continue;
                    }

                    $this->deliver($accountId, $this->streams[$accountId]['parser']->feed($chunk->getContent()));

                    if (true === $chunk->isLast()) {
                        // The server ended a stream it was asked to keep
                        // open: a restart on its side. Back soon.
                        $this->close($accountId, false);
                    }
                } catch (HttpClientException $e) {
                    $this->failed($accountId, $e);
                }
            }
        } catch (HttpClientException $e) {
            $this->logger->warning('JmapListen: waiting on the streams failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param list<array{event: string, data: string}> $events
     */
    private function deliver(int $accountId, array $events): void
    {
        $changes = JmapEventStream::stateChanges($events);

        if ([] === $changes) {
            return;
        }

        $account = $this->accounts->find($accountId);

        if (null === $account) {
            $this->close($accountId, false);

            return;
        }

        foreach ($changes as $change) {
            try {
                $this->fanout->dispatch($account, $change, $this->streams[$accountId]['accountIds'] ?? []);
            } catch (Throwable $e) {
                // A sync not queued is a sync the next poll does; a listener
                // that died over it would miss every account's next change.
                $this->logger->error('JmapListen: a change could not be queued', [
                    'accountId' => $accountId,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        // A stream that delivered is a stream that works: the next failure
        // starts its backoff from the bottom again.
        $this->backoff[$accountId] = self::MIN_BACKOFF_SECONDS;
    }

    private function failed(int $accountId, Throwable $e): void
    {
        $wait = $this->backoff[$accountId] ?? self::MIN_BACKOFF_SECONDS;

        $this->logger->warning('JmapListen: the stream failed, retrying later', [
            'accountId'    => $accountId,
            'error'        => $e->getMessage(),
            'retryInSecs'  => $wait,
        ]);

        $this->close($accountId, true);

        $this->retryAt[$accountId] = microtime(true) + $wait;
        $this->backoff[$accountId] = min(self::MAX_BACKOFF_SECONDS, 2 * $wait);
    }

    private function close(int $accountId, bool $failed): void
    {
        $stream = $this->streams[$accountId] ?? null;

        unset($this->streams[$accountId]);

        if (null !== $stream) {
            $stream['response']->cancel();
        }

        if (false === $failed) {
            $this->retryAt[$accountId] = microtime(true) + 1;
        }
    }

    private function sleep(float $seconds): void
    {
        $until = microtime(true) + $seconds;

        while (false === $this->shouldStop && microtime(true) < $until) {
            usleep(250_000);
        }
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
