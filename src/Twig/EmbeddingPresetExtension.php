<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Ai\EmbeddingPreset;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `embedding_presets()` — the measured model presets, ready to render.
 *
 * A function rather than a controller variable, for the reason
 * AiAvailabilityExtension gives about `signature_map()`: admin/ai/_frame.html.twig
 * is rendered from three actions — the settings page, the save, and the
 * connection test — and `strict_variables` is on, so a variable passed by two
 * of the three is a 500 on the third rather than a missing list. That third
 * path is the connection test, which is exactly when somebody is fiddling with
 * the model name and most likely to want the presets in front of them.
 *
 * Flattened to arrays here rather than handing the enum to Twig, so the
 * template has no logic in it: every value the preset buttons need is a key,
 * and the one thing that is not data — the translation of the summary — is left
 * as a key for the template to pass through `trans`.
 */
final class EmbeddingPresetExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('embedding_presets', $this->presets(...)),
        ];
    }

    /**
     * @return list<array{model: string, instruction: string, similarity: float, summary: string}>
     */
    private function presets(): array
    {
        return array_map(
            static fn (EmbeddingPreset $preset): array => [
                'model'       => $preset->value,
                'instruction' => $preset->queryInstruction(),
                'similarity'  => $preset->minSimilarity(),
                'summary'     => $preset->summaryKey(),
            ],
            EmbeddingPreset::ordered(),
        );
    }
}
