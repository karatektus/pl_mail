<?php

declare(strict_types=1);

namespace App\Domain\DTO\System;

/**
 * One long-running process the worker container keeps alive: what it is called
 * and what to run.
 */
final readonly class BackgroundProcess
{
    /**
     * @param string                $name    what it answers to everywhere: the heartbeat key, the
     *                                       log entries' container name, `app:work --only`
     * @param list<string>          $command argv, run from the project directory
     * @param array<string, string> $env     set on top of the container's own environment
     */
    public function __construct(
        public string $name,
        public array  $command,
        public array  $env = [],
    ) {
    }
}
