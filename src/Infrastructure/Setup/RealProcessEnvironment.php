<?php

declare(strict_types=1);

namespace App\Infrastructure\Setup;

/**
 * ProcessEnvironment over `getenv()`, which is the only reader that sees the
 * real environment and nothing else — see the interface for why that matters.
 */
final readonly class RealProcessEnvironment implements ProcessEnvironment
{
    public function get(string $name): ?string
    {
        $value = getenv($name);

        if (false === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
