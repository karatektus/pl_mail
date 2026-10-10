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

    /** Translation placeholders use percent-delimited names, unlike DTO keys. @return array<string, string|int> */
    public function getTranslationParameters(): array
    {
        $result = [];
        foreach ($this->reasonParams as $key => $value) $result['%' . trim($key, '%') . '%'] = $value;
        return $result;
    }

    public static function transportFailure(\Throwable $error): self
    {
        $text = strtolower($error->getMessage());
        $reason = match (true) {
            $error instanceof \Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface => 'timeout',
            str_contains($text, 'resolve host'), str_contains($text, 'getaddrinfo'), str_contains($text, 'dns') => 'dns',
            str_contains($text, 'certificate'), str_contains($text, 'ssl'), str_contains($text, 'tls') => 'tls',
            default => 'unreachable',
        };
        // Never expose exception text, URLs, headers, request data or credentials.
        return self::unreachable($reason);
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
            if ('OpenAI-compatible' !== $this->version && $name === explode(':', $model->name)[0]) {
                return true;
            }
        }

        return false;
    }
}
