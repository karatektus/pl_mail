<?php

declare(strict_types=1);

namespace App\Domain\DTO\Template;

use App\Domain\Enum\Template\TemplateVariable;

/**
 * One variable as it was written in a template: which one, and its settings.
 */
final readonly class TemplateToken
{
    /**
     * @param array<string, string> $arguments the `key=value` pairs after the
     *                                         name, e.g. offset and format
     */
    public function __construct(
        public TemplateVariable $variable,
        public array            $arguments = [],
    ) {
    }

    public function argument(string $name): ?string
    {
        return $this->arguments[$name] ?? null;
    }
}
