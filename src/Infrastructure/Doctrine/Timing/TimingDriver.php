<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Timing;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/** @see TimingMiddleware */
final class TimingDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private readonly QueryClock $clock)
    {
        parent::__construct($driver);
    }

    public function connect(#[SensitiveParameter] array $params): Connection
    {
        return new TimingConnection(parent::connect($params), $this->clock);
    }
}
