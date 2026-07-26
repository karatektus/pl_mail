<?php

declare(strict_types=1);

namespace App\Jmap\Protocol\Exception;

/**
 * A method-level error. Unlike request-level exceptions this does NOT abort the
 * whole request; the processor catches it and emits an inline
 * ["error", {type, ...extra}, callId] response for the offending method call,
 * then continues with the next call.
 */
final class MethodException extends JmapException
{
    /**
     * @param array<string,mixed> $extra
     */
    public function __construct(
        public readonly string $errorType,
        string $message = '',
        public readonly array $extra = [],
    ) {
        if ('' === $message) {
            $message = $errorType;
        }

        parent::__construct($message);
    }
}
