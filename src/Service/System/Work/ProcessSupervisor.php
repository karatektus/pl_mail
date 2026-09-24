<?php

declare(strict_types=1);

namespace App\Service\System\Work;

use App\Domain\DTO\System\BackgroundProcess;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Keeps a set of processes running: what Docker's `restart: unless-stopped`
 * did for them when each was a container of its own.
 *
 * A child that exits is started again. One that exited cleanly (a consumer at
 * its hourly --time-limit, or told to by the restart signal) comes back at once;
 * one that failed backs off, from one second doubling to thirty, so a process
 * that cannot start (the database is down) does not spin. A child that has run
 * two minutes has its backoff forgotten, the rule app:imap:supervise uses for
 * its own children.
 *
 * ── Output ──────────────────────────────────────────────────────────────────
 * Every child's output is passed through as it is, a whole line at a time. In
 * production each line is a JSON log record, and two children writing at once
 * must not interleave inside one: that is a line nobody can parse. Nothing is
 * prefixed either, for the same reason; each child already names itself in its
 * records through APP_CONTAINER_NAME.
 *
 * ── Stopping ────────────────────────────────────────────────────────────────
 * SIGTERM to every child at once, then up to the grace period for them to
 * finish what they are doing (a consumer finishes its message, the hub closes
 * its connections), then SIGKILL for whatever is left. The container's
 * stop_grace_period has to be longer than this, or Docker kills the lot first.
 */
final class ProcessSupervisor
{
    /** @var array<string, array{definition: BackgroundProcess, process: ?Process, startedAt: float, notBefore: float, backoff: float, restarts: int}> */
    private array $children = [];

    /** @var array<string, string> partial lines, by child and stream */
    private array $partial = [];

    /** @var \Closure(string, string): void */
    private \Closure $write;

    /**
     * @param (\Closure(string, string): void)|null $write where a child's line goes: (stream, line).
     *                                                     stdout and stderr of this process by default.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string          $projectDir,
        private readonly float           $minBackoff = 1.0,
        private readonly float           $maxBackoff = 30.0,
        private readonly float           $forgiveAfter = 120.0,
        ?\Closure                        $write = null,
    ) {
        $this->write = $write ?? static function (string $stream, string $line): void {
            fwrite(Process::ERR === $stream ? STDERR : STDOUT, $line . "\n");
        };
    }

    /**
     * Start every process, keep them running until $shouldStop says so, then
     * stop them all.
     *
     * @param list<BackgroundProcess> $processes
     * @param \Closure(): bool        $shouldStop
     */
    public function run(array $processes, \Closure $shouldStop, int $graceSeconds = 25): void
    {
        $this->start($processes);

        while (false === $shouldStop()) {
            $this->tick();
            usleep(200_000);
        }

        $this->stop($graceSeconds);
    }

    /** @param list<BackgroundProcess> $processes */
    public function start(array $processes): void
    {
        foreach ($processes as $definition) {
            $this->children[$definition->name] = [
                'definition' => $definition,
                'process'    => null,
                'startedAt'  => 0.0,
                'notBefore'  => 0.0,
                'backoff'    => $this->minBackoff,
                'restarts'   => -1,
            ];

            $this->launch($definition->name);
        }
    }

    /**
     * One pass: pass on what every child has written, notice the ones that
     * have exited, and start again the ones whose wait is over.
     */
    public function tick(): void
    {
        $now = microtime(true);

        foreach ($this->children as $name => $child) {
            $process = $child['process'];

            if (null === $process) {
                if ($now >= $child['notBefore']) {
                    $this->launch($name);
                }

                continue;
            }

            // isRunning() is also what reads the pipes, and so what calls the
            // output callback: every child is asked every tick for that alone.
            if (true === $process->isRunning()) {
                if ($now - $child['startedAt'] >= $this->forgiveAfter) {
                    $this->children[$name]['backoff'] = $this->minBackoff;
                }

                // The callback has had it; do not keep a copy in memory for as
                // long as the child lives, which for the hub is weeks.
                $process->clearOutput();
                $process->clearErrorOutput();

                continue;
            }

            $this->flush($name);
            $this->exited($name, $process->getExitCode(), $now);
        }
    }

    public function stop(int $graceSeconds): void
    {
        foreach ($this->children as $child) {
            if (true === $child['process']?->isRunning()) {
                $child['process']->signal(SIGTERM);
            }
        }

        $deadline = microtime(true) + $graceSeconds;

        do {
            $running = 0;

            foreach ($this->children as $child) {
                if (true === $child['process']?->isRunning()) {
                    ++$running;
                }
            }

            if (0 === $running) {
                break;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        foreach ($this->children as $name => $child) {
            if (true === $child['process']?->isRunning()) {
                $this->logger->warning('app:work: {process} did not stop in time and was killed', ['process' => $name]);
                $child['process']->stop(0);
            }

            $this->flush($name);
        }
    }

    /**
     * What is running now, by name: its pid and how often it has been restarted.
     *
     * @return array<string, array{pid: ?int, restarts: int}>
     */
    public function status(): array
    {
        $status = [];

        foreach ($this->children as $name => $child) {
            $status[$name] = [
                'pid'      => true === $child['process']?->isRunning() ? $child['process']->getPid() : null,
                'restarts' => max(0, $child['restarts']),
            ];
        }

        return $status;
    }

    private function launch(string $name): void
    {
        $definition = $this->children[$name]['definition'];

        $process = new Process(
            $definition->command,
            $this->projectDir,
            ['APP_CONTAINER_NAME' => $definition->name] + $definition->env,
        );
        $process->setTimeout(null);

        try {
            $process->start(function (string $type, string $buffer) use ($name): void {
                $this->receive($name, $type, $buffer);
            });
        } catch (\Throwable $e) {
            // A binary that is not there, a descriptor limit: counted as a
            // failed start, backed off like one.
            $this->logger->error('app:work: {process} could not be started: {error}', ['process' => $name, 'error' => $e->getMessage()]);
            $this->exited($name, null, microtime(true));

            return;
        }

        $this->children[$name]['process']   = $process;
        $this->children[$name]['startedAt'] = microtime(true);
        ++$this->children[$name]['restarts'];
    }

    private function exited(string $name, ?int $code, float $now): void
    {
        $child = $this->children[$name];

        // Ran long enough to have been healthy: whatever ended it, it is not
        // the crash loop the backoff is for.
        $backoff = null !== $child['process'] && $now - $child['startedAt'] >= $this->forgiveAfter
            ? $this->minBackoff
            : $child['backoff'];

        // 0 is a consumer at its time or memory limit, or asked to restart:
        // the recycling Docker used to do, and it comes straight back.
        $delay = 0 === $code ? $this->minBackoff : $backoff;

        if (0 === $code) {
            $this->logger->info('app:work: {process} exited cleanly, starting it again', ['process' => $name]);
        } else {
            $this->logger->warning('app:work: {process} exited with code {code}, starting it again in {delay}s', [
                'process' => $name,
                'code'    => $code,
                'delay'   => $delay,
            ]);
        }

        $this->children[$name]['process']   = null;
        $this->children[$name]['notBefore'] = $now + $delay;
        $this->children[$name]['backoff']   = 0 === $code ? $this->minBackoff : min($backoff * 2, $this->maxBackoff);
    }

    private function receive(string $name, string $type, string $buffer): void
    {
        $key  = $name . '|' . $type;
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

    /** What a child wrote without a final newline, once it can write no more. */
    private function flush(string $name): void
    {
        foreach ([Process::OUT, Process::ERR] as $type) {
            $key = $name . '|' . $type;

            if ('' !== ($this->partial[$key] ?? '')) {
                ($this->write)($type, $this->partial[$key]);
            }

            unset($this->partial[$key]);
        }
    }
}
