<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\SwipeAffordance;
use App\Domain\Enum\Mail\SwipeAction;
use App\Domain\Enum\Mail\SwipeDirection;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use LogicException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Which swipe actions a row offers, and the route, body and label of each.
 *
 * The question the row's hover strip already answers, asked once more for the
 * swipe: an action is offered on a row exactly where the strip draws its button
 * (D4 in the touch-gesture plan). The rule lives in SwipeAction::needsOrdinaryMail()
 * and the placement comes from MailPlacement, so this service decides nothing
 * the strip does not already decide; it only names the answer for the client.
 *
 * What it reads from the row: the placement (through MailPlacement, never by
 * re-deriving it from labels), whether the row is a draft (the caller's
 * template decides that, from the same flag the strip uses), and the row's own
 * unread and starred state, which the labels are drawn from.
 *
 * Directions are resolved through SwipeDirection::defaultAction(), which is the
 * fixed default. A per-user setting replaces that call, and nothing else here.
 * Every URL comes from the router by route name, so a route that is renamed
 * breaks here, in a test, rather than posting to a path that no longer exists.
 *
 * Asking for an action that has no mapping throws, naming it (invariant 8): the
 * enum's requireRoute() and the label and icon checks below are the tripwires,
 * and a new case fails the enum tests before it reaches a row.
 */
final class SwipeActionResolver
{
    public function __construct(
        private readonly MailPlacement $placement,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** The action a direction does on this row, by its fixed default. Null when it is inert here. */
    public function forDirection(SwipeDirection $direction, MessageThread|Message $item, bool $draft): ?SwipeAffordance
    {
        return $this->for($direction->defaultAction(), $item, $draft);
    }

    /**
     * The affordance for one action on this row, or null when the strip would
     * not offer it there. `None` is never offered.
     */
    public function for(SwipeAction $action, MessageThread|Message $item, bool $draft): ?SwipeAffordance
    {
        if (SwipeAction::None === $action) {
            return null;
        }

        if ($action->needsOrdinaryMail() && (true === $draft || MailPlacement::NORMAL !== $this->placement->of($item))) {
            return null;
        }

        $thread  = $item instanceof MessageThread;
        $unread  = $thread ? $item->unreadCount > 0 : null === $item->seenAt;
        $starred = null !== $item->starredAt;

        return new SwipeAffordance(
            action: $action,
            url: $this->urls->generate($action->requireRoute(), [
                'type' => $thread ? 'thread' : 'message',
                'id'   => $item->id,
            ]),
            body: $action->requestBody($unread),
            label: $action->labelKey($unread, $starred) ?? throw new LogicException(sprintf(
                'Swipe action "%s" has no label key: add one to labelKey().',
                $action->value,
            )),
            icon: $action->icon($unread, $starred) ?? throw new LogicException(sprintf(
                'Swipe action "%s" has no icon: add one to icon().',
                $action->value,
            )),
        );
    }
}
