<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Timing;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Times every statement on the way to the database, for QueryClock.
 *
 * At the driver, below the ORM and DBAL's own wrapper, so every statement is
 * counted however it was sent: DQL, the query builder, executeStatement() and
 * the prepared statements of the entity persisters alike.
 */
#[AsMiddleware]
final readonly class TimingMiddleware implements Middleware
{
    public function __construct(private QueryClock $clock)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new TimingDriver($driver, $this->clock);
    }
}
