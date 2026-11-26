<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Task\TextLinkifier;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `linkify`, which is marked safe because it escapes for itself: every piece of the
 * text goes through htmlspecialchars on the way out, so it takes the raw string
 * and must not be handed one Twig has already escaped.
 */
final class TaskExtension extends AbstractExtension
{
    public function __construct(
        private readonly TextLinkifier $linkifier,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('linkify', $this->linkifier->linkify(...), ['is_safe' => ['html']]),
        ];
    }
}
