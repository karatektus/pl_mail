<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

/**
 * One model an Ollama host is holding, as `/api/tags` reports it.
 *
 * A value object rather than an array because the admin form offers these in
 * two dropdowns — one for chat, one for embeddings — and a list of raw strings
 * would make the template do the parsing.
 */
final readonly class OllamaModel
{
    public function __construct(
        public string $name,
        public ?int   $sizeBytes = null,
        public ?string $family = null,
        public ?int   $parameterSize = null,
    ) {
    }

    /** "llama3.1:8b" reads better than the digest in a dropdown. */
    public function label(): string
    {
        return $this->name;
    }
}
