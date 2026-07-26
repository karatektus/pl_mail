<?php

declare(strict_types=1);

namespace App\Jmap\Method;

/**
 * Indexes every tagged JmapMethod by its name() for O(1) dispatch.
 */
final class MethodRegistry
{
    /** @var array<string,JmapMethod> */
    private array $methods = [];

    /**
     * @param iterable<JmapMethod> $methods
     */
    public function __construct(iterable $methods)
    {
        foreach ($methods as $method) {
            $this->methods[$method->name()] = $method;
        }
    }

    public function get(string $name): ?JmapMethod
    {
        return $this->methods[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->methods);
    }
}
