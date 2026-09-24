<?php

declare(strict_types=1);

namespace App\Tests\Service\System\Work;

use App\Domain\DTO\System\BackgroundProcess;
use App\Service\System\Work\ProcessSupervisor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What Docker's restart policy did for seven containers, done for seven
 * processes: brought back when they stop, backed off when they crash, stopped
 * together, and their output passed on whole.
 *
 * Real processes (`php -r`), because what is being tested is the handling of
 * real exits, real pipes and real signals. Timings are shrunk to milliseconds.
 */
final class ProcessSupervisorTest extends TestCase
{
    /** @var list<array{string, string}> */
    private array $written = [];

    public function testAProcessThatStopsCleanlyIsStartedAgainAtOnce(): void
    {
        $supervisor = $this->supervisor();
        $supervisor->start([$this->php('recycles', 'echo "one\ntwo\n"; exit(0);')]);

        $this->tickFor($supervisor, 0.6);

        self::assertGreaterThanOrEqual(2, $supervisor->status()['recycles']['restarts'], 'a clean exit is the hourly recycle: straight back');
        self::assertSame(['one', 'two'], array_slice(array_column($this->written, 1), 0, 2), 'whole lines, in order, nothing added');
    }

    /** A process that cannot start must not spin: each failure waits longer. */
    public function testAProcessThatKeepsFailingBacksOff(): void
    {
        $supervisor = $this->supervisor();
        $supervisor->start([$this->php('fails', 'exit(3);'), $this->php('recycles', 'exit(0);')]);

        $this->tickFor($supervisor, 0.8);

        $status = $supervisor->status();

        self::assertGreaterThan(0, $status['fails']['restarts']);
        self::assertLessThan($status['recycles']['restarts'], $status['fails']['restarts'], 'the failing one waits longer each time');
    }

    public function testStoppingSignalsEveryProcessAndWaitsForIt(): void
    {
        $supervisor = $this->supervisor();
        $supervisor->start([
            $this->php('polite', 'pcntl_async_signals(true); pcntl_signal(SIGTERM, function () { echo "finishing\n"; exit(0); }); while (true) { usleep(10000); }'),
        ]);

        $this->tickFor($supervisor, 0.3);
        $supervisor->stop(5);

        self::assertNull($supervisor->status()['polite']['pid'], 'nothing left running');
        self::assertContains('finishing', array_column($this->written, 1), 'it was asked, not killed');
    }

    private function supervisor(): ProcessSupervisor
    {
        return new ProcessSupervisor(
            new NullLogger(),
            sys_get_temp_dir(),
            minBackoff: 0.02,
            maxBackoff: 0.32,
            forgiveAfter: 60.0,
            write: function (string $stream, string $line): void {
                $this->written[] = [$stream, $line];
            },
        );
    }

    private function php(string $name, string $code): BackgroundProcess
    {
        return new BackgroundProcess($name, [PHP_BINARY, '-r', $code]);
    }

    private function tickFor(ProcessSupervisor $supervisor, float $seconds): void
    {
        $until = microtime(true) + $seconds;

        while (microtime(true) < $until) {
            $supervisor->tick();
            usleep(10_000);
        }
    }
}
