<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Timing;

use Symfony\Contracts\Service\ResetInterface;

/**
 * How many statements this request sent to the database, and how long it
 * waited for them. Read by ServerTimingSubscriber.
 *
 * Kept in production, where the profiler is not: the time a request spends
 * on Postgres is the first thing to know about a slow page, and on the NAS
 * plMail runs on it cannot be inferred from a laptop — a list that took 65 ms
 * here took 290 there. Two additions per statement cost nothing measurable.
 *
 * Resettable because FrankenPHP's workers keep it between requests; without
 * the reset every request would report all the ones before it.
 */
final class QueryClock implements ResetInterface
{
    public private(set) int $queries = 0;

    public private(set) float $milliseconds = 0.0;

    public function add(float $milliseconds): void
    {
        ++$this->queries;
        $this->milliseconds += $milliseconds;
    }

    public function reset(): void
    {
        $this->queries      = 0;
        $this->milliseconds = 0.0;
    }
}
