<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\DTO\Mail\SwipeAffordance;
use App\Domain\Enum\Mail\SwipeDirection;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Service\Mail\MailPlacement;
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
    public function __construct(
        private readonly SwipeActionResolver $swipe,
        private readonly MailPlacement $placement,
    )
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('swipe_action', $this->affordance(...)),
            new TwigFunction('sheet_entries', $this->sheetEntries(...)),
        ];
    }

    /** The affordance for a direction on a row, or null when that direction is inert there. */
    public function affordance(MessageThread|Message $item, string $direction, bool $draft): ?SwipeAffordance
    {
        return $this->swipe->forDirection(SwipeDirection::from($direction), $item, $draft);
    }

    /**
     * `sheet_entries(item, draft)`: the long-press sheet's entries for one row,
     * as the JSON the row carries in its data-sheet attribute.
     *
     * Each entry is keyed by its value and holds its URL (`u`) and, when the route
     * reads one, its body (`b`). A client-only entry (move, labels, select) is an
     * empty object: its presence is what the client reads. The type is named so the
     * label picker knows which kind of target it is ticking, and the placement so the
     * move picker can take out the list the row already sits in.
     *
     * @return array{type: string, placement: string, entries: array<string, array{u?: string, b?: array<string, bool>}|array{}>}
     */
    public function sheetEntries(MessageThread|Message $item, bool $draft): array
    {
        $entries = [];

        foreach ($this->swipe->sheet($item, $draft) as $key => $affordance) {
            if (null === $affordance->url) {
                $entries[$key] = [];

                continue;
            }

            $entries[$key] = [] === $affordance->body
                ? ['u' => $affordance->url]
                : ['u' => $affordance->url, 'b' => $affordance->body];
        }

        return [
            'type'      => $item instanceof MessageThread ? 'thread' : 'message',
            'placement' => $this->placement->of($item),
            'entries'   => $entries,
        ];
    }
}
