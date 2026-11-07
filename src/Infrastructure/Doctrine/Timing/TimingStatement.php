<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Timing;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** @see TimingMiddleware */
final class TimingStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $statement, private readonly QueryClock $clock)
    {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $start = hrtime(true);

        try {
            return parent::execute();
        } finally {
            $this->clock->add((hrtime(true) - $start) / 1e6);
        }
    }
}
