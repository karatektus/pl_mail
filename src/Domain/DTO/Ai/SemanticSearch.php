<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

use App\Domain\Enum\Ai\SemanticSkipReason;

/**
 * The vector one search binds — or the reason there is not one.
 *
 * WHY THE MODEL TRAVELS WITH THE VECTOR
 * ─────────────────────────────────────
 * Vectors from two embedding models are not comparable. Different width,
 * different space, and no error either way: on the shipped plpgsql distance
 * function a 768-wide query against 1024-wide rows is compared over the first
 * 768 components and answers a plausible-looking number that means nothing, and
 * on an installation that has pgvector the same comparison raises
 * `different vector dimensions` — a 500 on /mail/search for everybody, caused
 * by a dropdown in the admin panel.
 *
 * So the name and the width the query was embedded at ride along, and the SQL
 * matches on them. A mailbox half re-indexed after a model change searches the
 * half that matches and ignores the rest, rather than mixing two spaces and
 * ranking the result.
 *
 * The width is COUNTED FROM THE VECTOR rather than read from settings.
 * AiSettings::embeddingDimensions records what the model answered the first
 * time it was asked; this records what it answered just now, which is the thing
 * actually being compared.
 */
final readonly class SemanticSearch
{
    private function __construct(
        /** A PostgreSQL `real[]` literal, unit length, or null when the pass was skipped. */
        public ?string             $literal,
        public ?string             $model,
        public ?int                $dimensions,
        public ?SemanticSkipReason $skipped,
    ) {
    }

    public static function ran(string $literal, string $model, int $dimensions): self
    {
        return new self($literal, $model, $dimensions, null);
    }

    public static function skipped(SemanticSkipReason $reason): self
    {
        return new self(null, null, null, $reason);
    }

    /**
     * Named for the question the caller has, which is not "did this succeed"
     * but "is there something to bind" — a skipped pass is a normal outcome
     * here, not a failure to check for.
     */
    public function hasVector(): bool
    {
        return null !== $this->literal;
    }
}
