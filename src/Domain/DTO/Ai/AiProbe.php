<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

/**
 * What happened when we asked an Ollama host whether it was there.
 *
 * The admin form's "Test connection" answers with this, and it is deliberately
 * not a boolean: "could not connect" and "connected, but the model you named is
 * not installed" send an administrator to completely different places, and a
 * red cross for both would send them to the wrong one.
 *
 * `reason` is a translation KEY plus parameters, never a sentence — the same
 * rule AccountHealthInspector follows, and for the same reason: the service
 * layer does not know what language anybody reads.
 *
 * @phpstan-type ProbeParams array<string, string|int>
 */
final readonly class AiProbe
{
    /**
     * @param list<OllamaModel>   $models
     * @param array<string,string|int> $reasonParams
     */
    public function __construct(
        public bool   $reachable,
        public array  $models = [],
        public ?string $reason = null,
        public array  $reasonParams = [],
        public ?string $version = null,
    ) {
    }

    public static function reachable(array $models, ?string $version = null): self
    {
        return new self(true, $models, null, [], $version);
    }

    /**
     * @param array<string,string|int> $params
     */
    public static function unreachable(string $reason, array $params = []): self
    {
        return new self(false, [], $reason, $params);
    }

    public function hasModel(string $name): bool
    {
        foreach ($this->models as $model) {
            if ($model->name === $name) {
                return true;
            }

            // Ollama reports "llama3.1:8b"; an admin frequently types
            // "llama3.1", meaning the default tag. Treating those as different
            // would fail a test against a host that is holding exactly what was
            // asked for.
            if ($name === explode(':', $model->name)[0]) {
                return true;
            }
        }

        return false;
    }
}
