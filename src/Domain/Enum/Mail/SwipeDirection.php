<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * The two ways a row can be dragged, named by where it goes.
 *
 * Physical, not logical: Right is towards the right edge of the screen, whatever
 * the reading direction of the interface. No right-to-left locale ships, so a
 * reader adding one has to decide how the two sides mirror. The docs say so.
 *
 * `defaultAction()` is the one place the defaults live. Each direction's user
 * setting (User::swipeRightAction, User::swipeLeftAction) falls back to it, and
 * so does a signed-out reader, so the defaults stay visible here for anyone who
 * reads the enum first.
 */
enum SwipeDirection: string
{
    /** Dragged towards the right edge: read or unread by default. */
    case Right = 'right';

    /** Dragged towards the left edge: archive by default. */
    case Left = 'left';

    public function defaultAction(): SwipeAction
    {
        return match ($this) {
            self::Right => SwipeAction::ReadUnread,
            self::Left  => SwipeAction::Archive,
        };
    }
}
