<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

/**
 * One embedding, with what it cost and why it failed.
 *
 * The two-attempt shape of OllamaClient::embed() — the modern /api/embed then
 * the older /api/embeddings — collapses into ONE of these, and therefore into
 * one recorded row. Two rows for one logical embedding would double every call
 * count on a host old enough to need the fallback, which is precisely the host
 * an operator is most likely to be looking at the panel about.
 */
final readonly class AiEmbedResult
{
    /** @param list<float>|null $vector */
    public function __construct(
        public ?array       $vector,
        public AiCallTiming $timing,
        public bool         $succeeded,
        public ?string      $errorKind = null,
    ) {
    }

    /** @param list<float> $vector */
    public static function ok(array $vector, AiCallTiming $timing): self
    {
        return new self($vector, $timing, true);
    }

    public static function failed(string $errorKind, ?AiCallTiming $timing = null): self
    {
        return new self(null, $timing ?? AiCallTiming::none(), false, $errorKind);
    }
}
