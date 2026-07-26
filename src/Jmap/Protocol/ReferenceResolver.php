<?php

declare(strict_types=1);

namespace App\Jmap\Protocol;

use App\Jmap\Protocol\Exception\MethodException;

/**
 * Resolves JMAP result references (RFC 8620 §3.7). Any argument whose key
 * begins with "#" carries a { resultOf, name, path } reference that is
 * evaluated against a previous method call's result using a restricted
 * JSON Pointer (RFC 6901) that additionally supports the "*" wildcard.
 */
final class ReferenceResolver
{
    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    public function resolve(array $arguments, JmapContext $context): array
    {
        $resolved = [];

        foreach ($arguments as $key => $value) {
            if (true === str_starts_with((string) $key, '#')) {
                $realKey = substr((string) $key, 1);
                $resolved[$realKey] = $this->resolveReference($value, $context);
                continue;
            }

            $resolved[$key] = $value;
        }

        return $resolved;
    }

    private function resolveReference(mixed $reference, JmapContext $context): mixed
    {
        if (false === is_array($reference)) {
            throw new MethodException('invalidResultReference', 'Result reference must be an object.');
        }

        $resultOf = $reference['resultOf'] ?? null;
        $name = $reference['name'] ?? null;
        $path = $reference['path'] ?? null;

        if (false === is_string($resultOf) || false === is_string($name) || false === is_string($path)) {
            throw new MethodException('invalidResultReference', 'Result reference is missing resultOf, name or path.');
        }

        $result = $this->findResult($resultOf, $name, $context);

        if (null === $result) {
            throw new MethodException('invalidResultReference', 'Referenced method call produced no usable result.');
        }

        return $this->evaluatePointer($result, $path);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findResult(string $callId, string $name, JmapContext $context): ?array
    {
        foreach ($context->responses() as $response) {
            [$responseName, $responseResult, $responseCallId] = $response;

            if ($responseCallId !== $callId) {
                continue;
            }

            if ('error' === $responseName) {
                return null;
            }

            if ($responseName !== $name) {
                return null;
            }

            return $responseResult;
        }

        return null;
    }

    private function evaluatePointer(mixed $value, string $pointer): mixed
    {
        if ('' === $pointer) {
            return $value;
        }

        $tokens = explode('/', $pointer);

        if ('' === $tokens[0]) {
            array_shift($tokens);
        }

        return $this->applyTokens($value, $tokens);
    }

    /**
     * @param list<string> $tokens
     */
    private function applyTokens(mixed $value, array $tokens): mixed
    {
        if (count($tokens) === 0) {
            return $value;
        }

        $token = array_shift($tokens);

        if ('*' === $token) {
            if (false === is_array($value) || false === array_is_list($value)) {
                throw new MethodException('invalidResultReference', 'Wildcard "*" applied to a non-array value.');
            }

            $collected = [];

            foreach ($value as $item) {
                $mapped = $this->applyTokens($item, $tokens);

                if (true === is_array($mapped) && true === array_is_list($mapped)) {
                    foreach ($mapped as $entry) {
                        $collected[] = $entry;
                    }
                    continue;
                }

                $collected[] = $mapped;
            }

            return $collected;
        }

        $token = str_replace(['~1', '~0'], ['/', '~'], $token);

        if (false === is_array($value) || false === array_key_exists($token, $value)) {
            throw new MethodException('invalidResultReference', sprintf('Path segment "%s" not found in result.', $token));
        }

        return $this->applyTokens($value[$token], $tokens);
    }
}
