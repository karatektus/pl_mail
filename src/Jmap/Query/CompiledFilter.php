<?php

declare(strict_types=1);

namespace App\Jmap\Query;

use Doctrine\DBAL\ArrayParameterType;

/**
 * A compiled JMAP filter: a SQL fragment plus the parameters it binds.
 */
final class CompiledFilter
{
    /**
     * @param array<string,mixed> $parameters
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $parameters,
    ) {
    }

    /**
     * List parameters (inMailboxOtherThan) need an explicit array type so DBAL
     * expands them rather than binding an array as one value.
     *
     * @return array<string,ArrayParameterType|null>
     */
    public function parameterTypes(): array
    {
        $types = [];

        foreach ($this->parameters as $name => $value) {
            if (true === is_array($value)) {
                $types[$name] = ArrayParameterType::INTEGER;
            }
        }

        return $types;
    }
}
