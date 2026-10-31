<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

use Fiber;
use WeakMap;

/**
 * Runs many IMAP conversations at once in one process, each written as if it
 * were the only one.
 *
 * WHY FIBERS
 * ──────────
 * A conversation is a sequence — connect, secure, greet, log in, select, idle —
 * and every step waits on the server. Written as a state machine driven by
 * stream_select() it is correct and unreadable: each step becomes a state, each
 * wait a return, and the next person to add a command has to thread it through
 * all of them. With a Fiber the conversation is written top to bottom, and a
 * wait suspends it: this loop selects on every stream anybody is waiting for
 * and resumes the fibers whose stream is ready or whose deadline has passed.
 * One server that answers slowly, or not at all, holds up only its own fiber.
 *
 * WHY NOT A LIBRARY
 * ─────────────────
 * Revolt and ReactPHP do this and much more. What is needed here is a select
 * loop with deadlines, about the length of this file, and taking a framework
 * for it would make every future change to the IMAP client a question about
 * that framework's model.
 *
 * BLOCKING CALLERS
 * ────────────────
 * The same code works from outside a fiber. A wait made there runs the loop
 * itself until that wait is over — so a command, a migration or a message
 * handler can use the client as an ordinary blocking one, and any tasks
 * already on the loop make progress meanwhile.
 *
 * WHAT STILL BLOCKS
 * ─────────────────
 * Name resolution: PHP has no asynchronous getaddrinfo(), so a connect blocks
 * for as long as the resolver takes. That is the system resolver, usually a
 * cache, and not the IMAP server, which is the thing that must not be able to
 * hold the others up.
 */
final class ImapLoop
{
    /**
     * The longest one select may sleep when nothing is due sooner. It bounds
     * how long a signal handler's flag can go unnoticed by the caller's loop.
     */
    private const float MAX_SELECT_SECONDS = 1.0;

    /**
     * Fibers waiting on a stream.
     *
     * @var array<int, array{fiber: Fiber, stream: resource, write: bool, deadline: float|null}>
     */
    private array $waiting = [];

    /** @var array<int, array{fiber: Fiber, until: float}> */
    private array $sleeping = [];

    /** @var list<array{fiber: Fiber, value: mixed}> */
    private array $runnable = [];

    /** @var WeakMap<Fiber, ImapTask> the fibers this loop started, and what became of each */
    private WeakMap $tasks;

    public function __construct()
    {
        $this->tasks = new WeakMap();
    }

    /**
     * Start a conversation. It runs from the next tick, not inside this call,
     * so a caller can start several before any of them blocks.
     */
    public function async(callable $work): ImapTask
    {
        $fiber = new Fiber($work);
        $task  = new ImapTask();

        $this->tasks[$fiber] = $task;
        $this->runnable[]    = ['fiber' => $fiber, 'value' => null];

        return $task;
    }

    /**
     * Wait until the stream can be read without blocking.
     *
     * @param resource $stream
     *
     * @return bool false when the deadline passed first
     */
    public function awaitReadable(mixed $stream, ?float $deadline): bool
    {
        return $this->await($stream, false, $deadline);
    }

    /**
     * Wait until the stream can be written to without blocking.
     *
     * @param resource $stream
     *
     * @return bool false when the deadline passed first
     */
    public function awaitWritable(mixed $stream, ?float $deadline): bool
    {
        return $this->await($stream, true, $deadline);
    }

    public function sleep(float $seconds): void
    {
        $fiber = $this->ownFiber();

        if (null === $fiber) {
            $this->block(fn () => $this->sleep($seconds));

            return;
        }

        $this->sleeping[spl_object_id($fiber)] = ['fiber' => $fiber, 'until' => microtime(true) + $seconds];

        Fiber::suspend();
    }

    /**
     * Run for at most $seconds: start what was started, and resume whatever
     * is ready. Returns as soon as nothing is left to do.
     */
    public function run(float $seconds): void
    {
        $until = microtime(true) + $seconds;

        do {
            $this->tick(max(0.0, $until - microtime(true)));
        } while (true === $this->isBusy() && microtime(true) < $until);
    }

    /**
     * Run a piece of work to its end from outside the loop, and hand back
     * what it returned or rethrow what it threw.
     *
     * This is what makes the client usable as a blocking one. Called from
     * inside one of this loop's own fibers it would select from within a
     * fiber the loop is meant to be resuming, so there it simply runs the
     * work in place: the waits inside suspend that fiber as they always do.
     */
    public function block(callable $work): mixed
    {
        if (null !== $this->ownFiber()) {
            return $work();
        }

        $task = $this->async($work);

        while (false === $task->isFinished) {
            $this->tick(self::MAX_SELECT_SECONDS);
        }

        return $task->result();
    }

    /** Whether anything is started, waiting or sleeping. */
    public function isBusy(): bool
    {
        return [] !== $this->runnable || [] !== $this->waiting || [] !== $this->sleeping;
    }

    /**
     * One pass: resume what can run, then sleep in select() until a stream is
     * ready or the nearest deadline, and resume what that woke.
     */
    public function tick(float $maxSeconds): void
    {
        $this->resumeRunnable();

        $now     = microtime(true);
        $timeout = min($maxSeconds, self::MAX_SELECT_SECONDS);
        $read    = [];
        $write   = [];

        foreach ($this->waiting as $id => $wait) {
            if (false === is_resource($wait['stream'])) {
                // Closed under the waiter; let it find out by trying.
                $this->wake($id, true);

                continue;
            }

            // Bytes PHP has already taken off the socket and buffered are
            // invisible to select(): the socket is drained, so it never shows
            // as readable again, and the waiter would sleep on data it has.
            if (false === $wait['write'] && 0 < stream_get_meta_data($wait['stream'])['unread_bytes']) {
                $this->wake($id, true);

                continue;
            }

            if (true === $wait['write']) {
                $write[$id] = $wait['stream'];
            } else {
                $read[$id] = $wait['stream'];
            }

            if (null !== $wait['deadline']) {
                $timeout = min($timeout, $wait['deadline'] - $now);
            }
        }

        foreach ($this->sleeping as $sleep) {
            $timeout = min($timeout, $sleep['until'] - $now);
        }

        if ([] !== $this->runnable) {
            $timeout = 0.0;
        }

        $timeout = max(0.0, $timeout);

        if ([] === $read && [] === $write) {
            if (0.0 < $timeout) {
                usleep((int) ($timeout * 1_000_000));
            }
        } else {
            $except  = null;
            $seconds = (int) $timeout;

            // A signal interrupts select() with a warning and a false return.
            // That is not a failure: the handler has run, and the caller's
            // loop will see its flag when this tick returns.
            set_error_handler(static fn (): bool => true);

            try {
                $ready = stream_select($read, $write, $except, $seconds, (int) (($timeout - $seconds) * 1_000_000));
            } finally {
                restore_error_handler();
            }

            if (false !== $ready) {
                foreach (array_keys($read + $write) as $id) {
                    $this->wake($id, true);
                }
            }
        }

        $now = microtime(true);

        foreach ($this->waiting as $id => $wait) {
            if (null !== $wait['deadline'] && $now >= $wait['deadline']) {
                $this->wake($id, false);
            }
        }

        foreach ($this->sleeping as $id => $sleep) {
            if ($now >= $sleep['until']) {
                unset($this->sleeping[$id]);
                $this->runnable[] = ['fiber' => $sleep['fiber'], 'value' => null];
            }
        }

        $this->resumeRunnable();
    }

    /**
     * @param resource $stream
     */
    private function await(mixed $stream, bool $write, ?float $deadline): bool
    {
        $fiber = $this->ownFiber();

        if (null === $fiber) {
            return $this->block(fn (): bool => $this->await($stream, $write, $deadline));
        }

        $this->waiting[spl_object_id($fiber)] = ['fiber' => $fiber, 'stream' => $stream, 'write' => $write, 'deadline' => $deadline];

        return true === Fiber::suspend();
    }

    private function wake(int $id, bool $ready): void
    {
        $fiber = $this->waiting[$id]['fiber'];

        unset($this->waiting[$id]);

        $this->runnable[] = ['fiber' => $fiber, 'value' => $ready];
    }

    private function resumeRunnable(): void
    {
        while ([] !== $this->runnable) {
            ['fiber' => $fiber, 'value' => $value] = array_shift($this->runnable);

            $task = $this->tasks[$fiber];

            try {
                if (false === $fiber->isStarted()) {
                    $fiber->start();
                } else {
                    $fiber->resume($value);
                }
            } catch (\Throwable $e) {
                // Kept on the task rather than thrown from here: one
                // conversation's failure is that conversation's to deal
                // with, and must not stop the loop the others run on.
                $task->fail($e);

                continue;
            }

            if (true === $fiber->isTerminated()) {
                $task->succeed($fiber->getReturn());
            }
        }
    }

    /** The fiber running now, if this loop started it. */
    private function ownFiber(): ?Fiber
    {
        $fiber = Fiber::getCurrent();

        return null !== $fiber && true === isset($this->tasks[$fiber]) ? $fiber : null;
    }
}
