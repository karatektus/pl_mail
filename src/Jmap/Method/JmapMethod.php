<?php

declare(strict_types=1);

namespace App\Jmap\Method;

use App\Jmap\Protocol\JmapContext;

/**
 * A single JMAP method (e.g. "Email/get"). Implementations are autoconfigured
 * with the "app.jmap_method" tag and indexed by name() in the MethodRegistry.
 *
 * Throw App\Jmap\Protocol\Exception\MethodException for method-level errors;
 * the processor turns those into inline ["error", ...] responses.
 */
interface JmapMethod
{
    /**
     * The fully-qualified method name as sent by clients, e.g. "Mailbox/get".
     */
    public function name(): string;

    /**
     * @param array<string,mixed> $arguments Result references already resolved.
     *
     * @return array<string,mixed> The method's result object.
     */
    public function handle(array $arguments, JmapContext $context): array;
}
