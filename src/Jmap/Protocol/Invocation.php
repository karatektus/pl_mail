<?php

declare(strict_types=1);

namespace App\Jmap\Protocol;

use App\Jmap\Protocol\Exception\InvalidRequestException;

/**
 * A single JMAP method call, i.e. one entry of "methodCalls":
 * [ "Email/get", { ...arguments }, "callId" ].
 */
final class Invocation
{
    /**
     * @param array<string,mixed> $arguments
     */
    public function __construct(
        public private(set) string $name,
        public private(set) array $arguments,
        public private(set) string $callId,
    ) {
    }

    /**
     * @param array<int,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (count($data) !== 3) {
            throw new InvalidRequestException('Each method call must be a triplet of [name, arguments, callId].');
        }

        [$name, $arguments, $callId] = array_values($data);

        if (false === is_string($name) || false === is_array($arguments) || false === is_string($callId)) {
            throw new InvalidRequestException('Malformed method call triplet.');
        }

        return new self($name, $arguments, $callId);
    }

    /**
     * @param array<string,mixed> $result
     *
     * @return array{0:string,1:array<string,mixed>,2:string}
     */
    public function toResult(array $result): array
    {
        return [$this->name, $result, $this->callId];
    }

    /**
     * @param array<string,mixed> $extra
     *
     * @return array{0:string,1:array<string,mixed>,2:string}
     */
    public function toError(string $type, array $extra = []): array
    {
        return ['error', array_merge(['type' => $type], $extra), $this->callId];
    }
}
