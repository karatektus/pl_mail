<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Domain\Enum\Mail\SwipeAction;

/**
 * One direction of one row, as the server has decided it: what the action is,
 * where it posts, with what body, and how its button reads.
 *
 * Built only by SwipeActionResolver, and only for an action that is offered on
 * this row. An inert direction has no affordance at all, rather than an empty
 * one, so a template can test for it and draw nothing.
 *
 * `label` is a translation key, not text: the template translates it, the same
 * way the row's strip does, so the swipe's button and the strip's button for the
 * same action read the same in every locale.
 */
final readonly class SwipeAffordance
{
    /**
     * @param array<string, bool> $body the JSON the route reads, empty when it reads nothing
     */
    public function __construct(
        public SwipeAction $action,
        public string $url,
        public array $body,
        public string $label,
        public string $icon,
    ) {
    }
}
