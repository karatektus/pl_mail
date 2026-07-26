<?php

declare(strict_types=1);

namespace App\Jmap\Protocol;

use App\Jmap\Protocol\Exception\InvalidRequestException;

/**
 * A parsed JMAP request envelope (RFC 8620 §3.3).
 */
final class JmapRequest
{
    /**
     * @param list<string>         $using
     * @param list<Invocation>     $methodCalls
     * @param array<string,string> $createdIds
     */
    public function __construct(
        public private(set) array $using,
        public private(set) array $methodCalls,
        public private(set) array $createdIds = [],
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $using = $payload['using'] ?? null;
        $rawCalls = $payload['methodCalls'] ?? null;

        if (false === is_array($using) || false === is_array($rawCalls)) {
            throw new InvalidRequestException('A JMAP request requires "using" and "methodCalls" arrays.');
        }

        $methodCalls = [];

        foreach ($rawCalls as $rawCall) {
            if (false === is_array($rawCall)) {
                throw new InvalidRequestException('Each method call must be an array.');
            }

            $methodCalls[] = Invocation::fromArray($rawCall);
        }

        $createdIds = $payload['createdIds'] ?? [];

        if (false === is_array($createdIds)) {
            throw new InvalidRequestException('"createdIds" must be an object when present.');
        }

        return new self(array_values($using), $methodCalls, $createdIds);
    }
}
