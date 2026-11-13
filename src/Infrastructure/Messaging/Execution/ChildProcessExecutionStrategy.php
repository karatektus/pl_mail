<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Execution;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Execution\MessageExecutionStrategyInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Process\Process;

/**
 * Handles every message a worker takes off its queue in a process of its own,
 * so that one job that does not come back cannot hold up the others (#610).
 *
 * ── What happened ───────────────────────────────────────────────────────────
 * worker-ingest was one process handling one message at a time, and the
 * `ingest` queue holds everything that talks to a mail provider, for every
 * account: the syncs, the message batches, the IMAP folders, calendars and
 * address books. On 13 November 2026 Microsoft Graph stopped answering
 * properly, a job for an Outlook account sat inside a call to it, and for an
 * hour no account on the installation synced at all — Gmail and plain IMAP
 * included, which had nothing to do with Microsoft. One provider being down
 * must not be able to do that.
 *
 * ── What this does ──────────────────────────────────────────────────────────
 * Symfony's Worker still does everything it always did: fetching, the retry
 * ladder, the failed queue, the heartbeat, the time and memory limits, the stop
 * signals. Only the step in the middle — "now handle it" — is replaced. Each
 * message is handed, serialized, to `app:messenger:handle-one`, which runs it
 * through the bus exactly as the Worker would have, and the worker goes back to
 * the queue without waiting. When the child exits, the Worker is told how it
 * went, and acknowledges or retries the original as if it had handled it
 * itself.
 *
 * Three rules decide what may start:
 *
 *   - At most `limit()` children at once — the admin's "mail jobs at once"
 *     (Admin → Performance), re-read while running.
 *   - At most one child per ISOLATION KEY, usually the account. The same
 *     account's jobs still run one after another, in the order they arrived:
 *     a batch queued by a sync waits for the sync, and a mailbox is never
 *     asked for by two connections of plMail's at once — IMAP servers limit
 *     connections per user, and Graph allows about four concurrent requests
 *     per mailbox. Different accounts run side by side.
 *   - A child that has run for DEADLINE_SECONDS is killed. That is the part
 *     that makes "cannot be held up" true rather than likely: whatever the
 *     child was stuck in — a socket, a trickle, a token refresh, a library
 *     that never times out — ends with the process.
 *
 * ── What a killed or crashed child means ────────────────────────────────────
 * A child that exits 0 has finished with the message one way or the other:
 * handled it, or failed and already put it on the retry ladder or the failed
 * queue itself, the way the Worker does. Anything else — killed at the
 * deadline, a fatal error, out of memory — left the message where it was, and
 * this class decides:
 *
 *   - A job that is asked for again anyway ($askedAgain: the account sync, an
 *     IMAP folder sync) is dropped with one warning. It resumes from a cursor
 *     that only moves when it succeeds, and the schedule or the next push
 *     brings it back. Retrying a sync that hung five times would be five more
 *     hung children. ImapDeadline makes the same choice for the same jobs.
 *   - Anything else — above all the message batches, which nothing lists again
 *     — goes to the Worker as a failure, and so onto the retry ladder like any
 *     other: delayed, counted, and on the failed queue at the end of it.
 *
 * ── The roads not taken ─────────────────────────────────────────────────────
 * More worker-ingest processes: helps against one slow account, but during an
 * outage every job for that provider hangs, and with three Outlook accounts
 * three workers are three stuck workers. A queue per provider: a Microsoft
 * outage would still hold up every Microsoft account, and one stuck Outlook
 * account the other Outlook accounts. A pool of long-lived children fed over
 * pipes: saves the boot of a Symfony kernel per job (a few hundred
 * milliseconds), but a child that has to be killed takes its pool slot's state
 * with it, and "kill it" is the one operation this must make simple.
 *
 * The cost is that boot, and up to `limit()` PHP processes of about 50 MB each
 * while jobs are running — none at all while the queue is empty.
 */
final class ChildProcessExecutionStrategy implements MessageExecutionStrategyInterface
{
    /**
     * How long a child may run. A minute past ImapDeadline's five, so an IMAP
     * job that stalls is ended gracefully by that first and this is the
     * backstop for everything ImapDeadline cannot see: HTTP, OAuth, DNS.
     */
    public const int DEADLINE_SECONDS = 360;

    /**
     * How long children get to finish once the worker has been told to stop.
     * Below app:work's 25 seconds, which is below the container's 30: the
     * children are killed here, by the process that knows which message each
     * one was handling, and not by Docker.
     */
    public const int STOP_GRACE_SECONDS = 20;

    /** How long a wait sleeps between looks at the children. */
    private const int POLL_MICROSECONDS = 100_000;

    /** @var list<array{envelope: Envelope, transport: string, key: ?string}> */
    private array $waiting = [];

    /** @var list<array{envelope: Envelope, transport: string, key: ?string, process: Process, startedAt: float, killed: ?string}> */
    private array $running = [];

    /** @var array<string, string> partial lines, by child and stream */
    private array $partial = [];

    private bool $stopping = false;

    private ?float $stoppingSince = null;

    /**
     * @param \Closure(string): list<string>       $command    the child's command line, for a transport
     * @param \Closure(object): ?string            $keyOf      the isolation key of a message, null for none
     * @param \Closure(): int                      $limit      how many children may run at once
     * @param \Closure(): void                     $whileBusy  called while waiting on children (the heartbeat)
     * @param list<class-string>                   $askedAgain messages dropped rather than retried when their child dies
     * @param (\Closure(string, string): void)|null $write     where a child's line goes: (stream, line)
     */
    public function __construct(
        private readonly \Closure            $command,
        private readonly SerializerInterface $serializer,
        private readonly \Closure            $keyOf,
        private readonly \Closure            $limit,
        private readonly LoggerInterface     $logger,
        private readonly \Closure            $whileBusy,
        private readonly array               $askedAgain = [],
        private readonly float               $deadlineSeconds = self::DEADLINE_SECONDS,
        private readonly float               $stopGraceSeconds = self::STOP_GRACE_SECONDS,
        private ?\Closure                    $write = null,
    ) {
        $this->write ??= static function (string $stream, string $line): void {
            fwrite(Process::ERR === $stream ? STDERR : STDOUT, $line . "\n");
        };
    }

    /**
     * Take the message, and come back as soon as there is room for another.
     *
     * "Room" counts the ones waiting for their account as well as the running
     * ones. Without that, a queue full of one account's batches would be
     * fetched in its entirety into this process's memory while they waited
     * their turn — and fetched means marked delivered on the transport, for
     * longer than anything else would expect.
     */
    public function execute(Envelope $envelope, string $transportName, callable $onHandled): void
    {
        if (true === $this->inHand($envelope, $transportName)) {
            return;
        }

        $this->waiting[] = [
            'envelope'  => $envelope,
            'transport' => $transportName,
            'key'       => ($this->keyOf)($envelope->getMessage()),
        ];

        $this->pump($onHandled);

        while (count($this->running) >= $this->limit() || count($this->waiting) >= $this->limit()) {
            // Told to stop while waiting for room: nothing more will start, so
            // the room would never come. The Worker's flush(true), next, hands
            // back what is waiting and cuts the children short.
            if (true === $this->stopping) {
                return;
            }

            ($this->whileBusy)();
            usleep(self::POLL_MICROSECONDS);
            $this->pump($onHandled);
        }
    }

    public function shouldPauseConsumption(): bool
    {
        return count($this->running) >= $this->limit();
    }

    public function wait(callable $onHandled): bool
    {
        return $this->pump($onHandled) > 0;
    }

    /**
     * Report what has finished; with $force === true, wait for everything.
     *
     * The Worker calls this with `true` once, as it stops, and with `false` or
     * a float between messages; only the first means "wait". How long depends
     * on why it is stopping: told to by a signal, the container is going away
     * and the children get STOP_GRACE_SECONDS; at its hourly time limit, they
     * get their own deadline, so a recycle never cuts a job short.
     */
    public function flush(callable $onHandled, bool|float $force = false): bool
    {
        $handled = $this->pump($onHandled) > 0;

        if (true !== $force) {
            return $handled;
        }

        while ([] !== $this->running || [] !== $this->waiting) {
            if (true === $this->stopping) {
                $this->handBackWaiting($onHandled);
            }

            ($this->whileBusy)();
            usleep(self::POLL_MICROSECONDS);
            $handled = $this->pump($onHandled) > 0 || $handled;
        }

        return $handled;
    }

    public function shutdown(): void
    {
        $this->stop();
    }

    /** The worker is going away: start nothing new, and give the children only the grace period. */
    public function stop(): void
    {
        $this->stopping        = true;
        $this->stoppingSince ??= microtime(true);
    }

    /** How many children are running now; for the tests and the log. */
    public function runningCount(): int
    {
        return count($this->running);
    }

    /**
     * One pass: end what is overdue, report what has exited, start what may.
     *
     * @return int how many messages were reported to the Worker
     */
    private function pump(callable $onHandled): int
    {
        $reported = 0;
        $now      = microtime(true);

        foreach ($this->running as $index => $child) {
            $process = $child['process'];

            // isRunning() is also what reads the pipes and so what passes the
            // child's output on: every child is asked every pass for that alone.
            if (true === $process->isRunning()) {
                $deadline = true === $this->stopping
                    ? min($child['startedAt'] + $this->deadlineSeconds, (float) $this->stoppingSince + $this->stopGraceSeconds)
                    : $child['startedAt'] + $this->deadlineSeconds;

                if ($now < $deadline) {
                    $process->clearOutput();
                    $process->clearErrorOutput();

                    continue;
                }

                $child['killed'] = true === $this->stopping && $now - $child['startedAt'] < $this->deadlineSeconds
                    ? 'the worker was stopping'
                    : sprintf('it ran for longer than %d seconds', (int) $this->deadlineSeconds);

                // SIGTERM, then SIGKILL after two seconds: a PHP process with
                // no signal handler ends on the first, and the second is for
                // one stuck in an uninterruptible call.
                $process->stop(2);
            }

            unset($this->running[$index]);
            $this->flushOutput($process);
            $this->report($child, $process->getExitCode(), $onHandled);
            ++$reported;
        }

        $this->running = array_values($this->running);

        if (false === $this->stopping) {
            $this->launchWhatMay();
        }

        return $reported;
    }

    /**
     * Start every waiting message whose key is free, oldest first, while there
     * is room. A message whose account is busy keeps its place; the ones
     * behind it for other accounts do not wait for it.
     */
    private function launchWhatMay(): void
    {
        $busy = [];

        foreach ($this->running as $child) {
            if (null !== $child['key']) {
                $busy[$child['key']] = true;
            }
        }

        foreach ($this->waiting as $index => $job) {
            if (count($this->running) >= $this->limit()) {
                break;
            }

            if (null !== $job['key'] && true === isset($busy[$job['key']])) {
                continue;
            }

            unset($this->waiting[$index]);

            if (true === $this->launch($job)) {
                if (null !== $job['key']) {
                    $busy[$job['key']] = true;
                }
            }
        }

        $this->waiting = array_values($this->waiting);
    }

    /** @param array{envelope: Envelope, transport: string, key: ?string} $job */
    private function launch(array $job): bool
    {
        $encoded = $this->serializer->encode($job['envelope']);

        $process = new Process(($this->command)($job['transport']));
        $process->setTimeout(null);
        $process->setInput(json_encode($encoded, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));

        try {
            $process->start(function (string $type, string $buffer) use ($process): void {
                $this->receive(spl_object_id($process), $type, $buffer);
            });
        } catch (\Throwable $e) {
            // A descriptor limit, a binary that is not there. The message has
            // not been handled; the Worker's next look at this class gets it
            // back as a failure, onto the ladder.
            $this->logger->error('Could not start a process to handle {class}: {error}', [
                'class' => $job['envelope']->getMessage()::class,
                'error' => $e->getMessage(),
            ]);

            $this->running[] = $job + ['process' => $process, 'startedAt' => microtime(true), 'killed' => 'it could not be started'];

            return false;
        }

        $this->running[] = $job + ['process' => $process, 'startedAt' => microtime(true), 'killed' => null];

        return true;
    }

    /**
     * Tell the Worker how a child went.
     *
     * @param array{envelope: Envelope, transport: string, key: ?string, process: Process, startedAt: float, killed: ?string} $child
     */
    private function report(array $child, ?int $exitCode, callable $onHandled): void
    {
        $envelope = $child['envelope'];
        $message  = $envelope->getMessage();

        if (null === $child['killed'] && 0 === $exitCode) {
            $this->tell($onHandled, $envelope, $child['transport'], null);

            return;
        }

        $why = $child['killed'] ?? sprintf('it exited with code %s', null === $exitCode ? 'unknown' : (string) $exitCode);

        if (true === in_array($message::class, $this->askedAgain, true)) {
            $this->logger->warning('Skipped {class}: {why}. It is asked for again by the schedule and the next push, and resumes from the same point.', [
                'class' => $message::class,
                'why'   => $why,
                'key'   => $child['key'],
            ]);

            // Reported as handled, which the Worker reads as "acknowledge":
            // off the queue, not retried, not parked.
            $this->tell($onHandled, $envelope, $child['transport'], null);

            return;
        }

        $this->tell($onHandled, $envelope, $child['transport'], new ChildProcessFailedException(sprintf(
            'The process handling %s did not finish: %s.',
            $message::class,
            $why,
        )));
    }

    /**
     * Messages that never got a child, when the worker is stopping: back to
     * the Worker as failures, so the retry ladder puts them on the queue again
     * a few seconds from now rather than leaving them marked delivered until
     * the transport's redelivery timeout, an hour away.
     */
    private function handBackWaiting(callable $onHandled): void
    {
        foreach ($this->waiting as $job) {
            $this->tell($onHandled, $job['envelope'], $job['transport'], new ChildProcessFailedException(sprintf(
                '%s was not started before the worker stopped.',
                $job['envelope']->getMessage()::class,
            )));
        }

        $this->waiting = [];
    }

    /**
     * Whether this message is already running or waiting here.
     *
     * The Worker asks the transport for more while children are still busy
     * with what it took before, and not every transport hides a message it
     * has handed out until that message is acknowledged. Doctrine's does
     * (delivered_at); the in-memory one the tests use does not, and gave the
     * same message back on every fetch — five children for one sync. Matched
     * by the id the transport gave it, which is what the transport itself
     * would use to acknowledge it.
     */
    private function inHand(Envelope $envelope, string $transportName): bool
    {
        $id = $envelope->last(TransportMessageIdStamp::class)?->getId();

        if (null === $id) {
            return false;
        }

        foreach ([...$this->running, ...$this->waiting] as $job) {
            if ($job['transport'] === $transportName
                && $job['envelope']->last(TransportMessageIdStamp::class)?->getId() === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Report one message to the Worker.
     *
     * Through a variable, because the Worker's callback takes `$acked` by
     * reference: a literal there is a fatal error. False, always — the
     * message has not been acknowledged yet, and acknowledging it is exactly
     * what the Worker does next.
     */
    private function tell(callable $onHandled, Envelope $envelope, string $transportName, ?\Throwable $error): void
    {
        $acked = false;

        $onHandled($envelope, $transportName, $acked, $error);
    }

    private function limit(): int
    {
        return max(1, ($this->limit)());
    }

    /**
     * A child's output, passed on a whole line at a time: in production every
     * line is a JSON log record, and two children writing at once must not
     * interleave inside one. ProcessSupervisor does the same one level up.
     */
    private function receive(int $child, string $type, string $buffer): void
    {
        $key  = $child . '|' . $type;
        $text = ($this->partial[$key] ?? '') . $buffer;
        $cut  = strrpos($text, "\n");

        if (false === $cut) {
            $this->partial[$key] = $text;

            return;
        }

        $this->partial[$key] = substr($text, $cut + 1);

        foreach (explode("\n", substr($text, 0, $cut)) as $line) {
            ($this->write)($type, $line);
        }
    }

    private function flushOutput(Process $process): void
    {
        $child = spl_object_id($process);

        foreach ([Process::OUT, Process::ERR] as $type) {
            $key = $child . '|' . $type;

            if ('' !== ($this->partial[$key] ?? '')) {
                ($this->write)($type, $this->partial[$key]);
            }

            unset($this->partial[$key]);
        }
    }
}
