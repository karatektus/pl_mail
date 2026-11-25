<?php

declare(strict_types=1);

namespace App\Service\Maintenance\Backfill;

use App\Entity\Maintenance\BackfillRun;
use Closure;
use Symfony\Component\Console\Output\Output;

/**
 * Where a backfill started from the admin page prints: into its run's row.
 *
 * The tasks were written for a terminal and print through SymfonyStyle, so
 * rather than teach twenty-odd tasks a second way to report, they are handed
 * an output that collects what they print and stores it.
 *
 * **Undecorated**, so a progress bar prints a line per step instead of
 * redrawing one line with escape codes, which a stored text cannot replay.
 *
 * **Stored every few seconds, not every line.** A task advancing its bar per
 * batch prints many times a second on a fast table, and a write per line would
 * make the ledger the slowest part of the run. The end of a run calls flush()
 * itself, so nothing printed is lost to the interval.
 */
final class BackfillRunOutput extends Output
{
    /** Seconds between two writes of the output to its row. */
    private const float STORE_INTERVAL = 2.0;

    private string $buffer = '';

    private float $storedAt = 0.0;

    /**
     * @param Closure(string): void $store writes the text so far to the run
     */
    public function __construct(
        private readonly Closure $store,
    ) {
        parent::__construct(self::VERBOSITY_NORMAL, false);
    }

    public function text(): string
    {
        return $this->buffer;
    }

    public function flush(): void
    {
        ($this->store)($this->buffer);

        $this->storedAt = microtime(true);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->buffer .= $message . (true === $newline ? "\n" : '');

        // The end is kept, because the end is where the result and any error
        // are; see BackfillRun::OUTPUT_LIMIT.
        if (strlen($this->buffer) > BackfillRun::OUTPUT_LIMIT) {
            $this->buffer = mb_strcut($this->buffer, strlen($this->buffer) - BackfillRun::OUTPUT_LIMIT);
        }

        if (microtime(true) - $this->storedAt >= self::STORE_INTERVAL) {
            $this->flush();
        }
    }
}
