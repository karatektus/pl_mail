<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

/**
 * What Ollama said a call cost, in its own words.
 *
 * Every duration is NANOSECONDS, which is Ollama's unit and not a choice made
 * here. A two-minute generation is 1.2e11 — thirty times what a 32-bit column
 * holds — which is why the columns behind this are bigint.
 *
 * Every field is nullable because absence is real and common: an embedding has
 * no generation phase, so /api/embed returns no eval_count and no
 * eval_duration, and the legacy /api/embeddings returns no timings at all.
 * Null is the truth for those. Zero would be a lie sitting in the middle of
 * every percentile.
 */
final readonly class AiCallTiming
{
    public function __construct(
        public ?int $promptTokens = null,
        public ?int $promptDurationNs = null,
        public ?int $evalTokens = null,
        public ?int $evalDurationNs = null,
        public ?int $loadDurationNs = null,
        public ?int $totalDurationNs = null,
    ) {
    }

    /** Nothing was measured, because the host never answered. */
    public static function none(): self
    {
        return new self();
    }

    /** @param array<string,mixed> $body */
    public static function fromBody(array $body): self
    {
        return new self(
            promptTokens:     self::whole($body['prompt_eval_count'] ?? null),
            promptDurationNs: self::whole($body['prompt_eval_duration'] ?? null),
            evalTokens:       self::whole($body['eval_count'] ?? null),
            evalDurationNs:   self::whole($body['eval_duration'] ?? null),
            loadDurationNs:   self::whole($body['load_duration'] ?? null),
            totalDurationNs:  self::whole($body['total_duration'] ?? null),
        );
    }

    /**
     * is_int rather than a cast.
     *
     * json_decode answers a float for anything past PHP_INT_MAX and for
     * anything a host writes as 1.2e11, and casting that would store a number
     * nobody measured. Absent is the honest answer, and every query downstream
     * already excludes absent. Same shape as OllamaClient's existing
     * is_int($entry['size'] ?? null) check.
     */
    private static function whole(mixed $value): ?int
    {
        if (false === is_int($value)) {
            return null;
        }

        return $value >= 0 ? $value : null;
    }
}
