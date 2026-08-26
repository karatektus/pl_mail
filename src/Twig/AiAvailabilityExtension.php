<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Ai\AiFeature;
use App\Service\Ai\AiAssistant;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `ai_writing_help_enabled()` — whether the composer should offer to help.
 *
 * A function rather than a controller variable, for the reason
 * `signature_map()` is one: compose/_window.html.twig is included from three
 * places — the two routes that render it and both undo streams, which include
 * it `only` — and `strict_variables` is on, so a variable threaded through two
 * of the three is a 500 on the undo path rather than a missing button. The next
 * caller would have to remember, and forgetting is invisible until somebody
 * cancels a send.
 */
final class AiAvailabilityExtension extends AbstractExtension
{
    public function __construct(private readonly AiAssistant $ai)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ai_writing_help_enabled', $this->writingHelp(...)),
        ];
    }

    public function writingHelp(): bool
    {
        return $this->ai->isEnabledFor(AiFeature::WritingHelp);
    }
}
