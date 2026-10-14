<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\DTO\Mail\SwipeAffordance;
use App\Domain\Enum\Mail\SwipeDirection;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Service\Mail\SwipeActionResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `swipe_action(item, 'left' | 'right', draft)` for the row template.
 *
 * Kept apart from MailPlacementExtension on purpose: that one is a pass-through
 * and stays one. This one turns a direction name into the enum, and nothing
 * more. The decision lives in SwipeActionResolver, so the template gets the
 * same answer the strip's own buttons are drawn from.
 */
final class SwipeActionExtension extends AbstractExtension
{
    public function __construct(private readonly SwipeActionResolver $swipe)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('swipe_action', $this->affordance(...)),
        ];
    }

    /** The affordance for a direction on a row, or null when that direction is inert there. */
    public function affordance(MessageThread|Message $item, string $direction, bool $draft): ?SwipeAffordance
    {
        return $this->swipe->forDirection(SwipeDirection::from($direction), $item, $draft);
    }
}
