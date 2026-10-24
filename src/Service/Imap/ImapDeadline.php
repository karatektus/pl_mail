<?php

declare(strict_types=1);

namespace App\Service\Imap;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\Protocol;
use WeakReference;

/**
 * Ends an IMAP conversation that has gone on too long, so the worker holding
 * it can do something else.
 *
 * ── What happened (#95) ─────────────────────────────────────────────────────
 * Both import consumers sat for twenty minutes and more inside one fetch from
 * Gmail's IMAP, which was delivering a few kilobytes every twenty seconds.
 * Nothing was wrong enough to fail. There IS a read timeout, thirty seconds,
 * but it is per read: a server that sends one byte inside every window never
 * trips it, and no clock anywhere measured the whole command. A consumer
 * handles one message at a time, so everything queued behind that fetch — the
 * other accounts' imports, unrelated to Gmail — waited with it. Asking the
 * consumer to stop did nothing either: it stops between messages, and it was
 * not between messages.
 *
 * ── What this does ──────────────────────────────────────────────────────────
 * While a worker handles a message there is an alarm running. If it goes off,
 * every IMAP connection opened since is closed underneath whoever is reading
 * it. The read fails at once, the handler fails the way it does when a
 * network drops — which all of this code already expects — and Messenger puts
 * the message back on its retry ladder, behind whatever was waiting.
 *
 * ── Why close the socket, rather than throw from the alarm ─────────────────
 * A signal handler runs between any two statements of whatever was executing.
 * An exception thrown from there would surface in the middle of a Doctrine
 * flush as readily as in the middle of a read, and unwinding half a unit of
 * work is how a worker ends up with a closed entity manager. Closing the
 * socket changes nothing except for code that is talking to the server, and
 * that code fails where it stands, at a line that has always been able to.
 *
 * It also matters for what comes next. Every caller disconnects in a
 * `finally`, and a polite logout reads to the server's goodbye — through the
 * same megabytes of trickle that were the problem. A socket that has been shut down has
 * no goodbye to wait for.
 *
 * ── The length ──────────────────────────────────────────────────────────────
 * Five minutes. It is a total and not a rate — nothing here can see how fast
 * a connection is moving, only how long the message has been in hand — so it
 * has to leave room for honest work, and the largest honest unit is one import
 * page: fifty messages. That is seconds for ordinary mail and a few minutes
 * for a page of large attachments on a slow line.
 *
 * It was fifteen at first, on the reasoning that a wrong cut costs a page
 * fetched again. But every minute of it is a minute all the other accounts
 * wait, and fifteen of them per stalled message is most of the stall the
 * report was about. What five risks instead is a page that genuinely needs
 * longer — three hundred megabytes of attachments at under eight megabits a
 * second — being cut every time it is tried. That shows as this class's
 * warning in the log, repeatedly, for the same folder; it is the sign to
 * raise this.
 *
 * Only where there is a worker and the means: `pcntl`, and a message that was
 * received from a transport. A web request, a console command and the IDLE
 * process — which holds its connection for twenty-nine minutes on purpose —
 * never arm it.
 */
final class ImapDeadline implements ResetInterface
{
    public const int SECONDS = 300;

    /** @var list<WeakReference<Client>> */
    private array $clients = [];

    /** How many handlers deep the worker is; only the outermost owns the alarm. */
    private int $depth = 0;

    private bool $installed = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $seconds = self::SECONDS,
    ) {
    }

    /** A connection that an expiring deadline should close. Held weakly: this must not keep one alive. */
    public function watch(Client $client): void
    {
        $this->clients = array_values(array_filter(
            $this->clients,
            static fn (WeakReference $reference): bool => null !== $reference->get(),
        ));

        $this->clients[] = WeakReference::create($client);
    }

    public static function available(): bool
    {
        return 'cli' === PHP_SAPI && true === function_exists('pcntl_alarm') && true === function_exists('pcntl_async_signals');
    }

    /** Start the clock for one handled message. Nested handling shares the outermost clock. */
    public function arm(): void
    {
        if (false === self::available() || $this->seconds <= 0) {
            return;
        }

        if (1 !== ++$this->depth) {
            return;
        }

        if (false === $this->installed) {
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, function (): void {
                $this->expire();
            });

            $this->installed = true;
        }

        // Only what is opened from here on belongs to this message.
        $this->clients = [];

        pcntl_alarm($this->seconds);
    }

    public function disarm(): void
    {
        if (false === self::available() || $this->seconds <= 0) {
            return;
        }

        if (0 !== --$this->depth) {
            $this->depth = max(0, $this->depth);

            return;
        }

        pcntl_alarm(0);
        $this->clients = [];
    }

    /**
     * Close every watched connection under whoever is using it.
     *
     * Public because it is the whole effect of the class and has to be
     * testable without waiting five minutes for a signal.
     *
     * @return int how many connections were closed
     */
    public function expire(): int
    {
        $closed = 0;

        foreach ($this->clients as $reference) {
            $client = $reference->get();

            if (null === $client) {
                continue;
            }

            try {
                $connection = $client->getConnection();
                $stream     = $connection instanceof Protocol ? $connection->getStream() : null;

                if (true === is_resource($stream)) {
                    // Shut down, not fclose()d. Both end the conversation;
                    // only this one leaves the library holding something it
                    // can still ask questions of. With the resource closed
                    // outright, its logout() — which every caller's `finally`
                    // reaches, and its destructor after them — dies on
                    // stream_get_meta_data() with a TypeError, from a
                    // destructor, wherever the garbage collector happens to
                    // be. Shut down, every read is simply at end-of-file: the
                    // library reports a lost connection, as it does when a
                    // network drops, and tidies up after itself.
                    if (false === @stream_socket_shutdown($stream, STREAM_SHUT_RDWR)) {
                        fclose($stream);
                    }

                    ++$closed;
                }
            } catch (\Throwable) {
                // Already gone, or never connected: nothing is being read from it.
            }
        }

        $this->clients = [];

        if ($closed > 0) {
            $this->logger->warning('An IMAP conversation outlasted its deadline and was closed', [
                'seconds'     => $this->seconds,
                'connections' => $closed,
            ]);
        }

        return $closed;
    }

    public function reset(): void
    {
        $this->clients = [];
    }
}
