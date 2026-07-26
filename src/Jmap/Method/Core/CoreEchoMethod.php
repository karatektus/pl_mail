<?php

declare(strict_types=1);

namespace App\Jmap\Method\Core;

use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\JmapContext;

/**
 * "Core/echo" (RFC 8620 §4). Returns its arguments unchanged. Handy as a
 * spec-compliant smoke test that the request envelope, dispatch loop and
 * auth firewall all work end to end.
 */
final class CoreEchoMethod implements JmapMethod
{
    public function name(): string
    {
        return 'Core/echo';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        return $arguments;
    }
}
