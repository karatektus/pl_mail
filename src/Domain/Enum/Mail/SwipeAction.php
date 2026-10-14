<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

use LogicException;

/**
 * What a swipe across a mail row does, once it commits.
 *
 * Deliberately no Trash case. A swipe commits on a full drag or a fling with no
 * tap in front of it, and trash has no Undo on a single row: a full swipe
 * would delete mail by accident, with nothing to take it back. Trash is
 * offered by the row's own strip and by the long-press sheet, where a tap
 * stands between the finger and the delete. Do not add it here to "complete"
 * the set; the case-set test in SwipeActionTest is the place that makes anyone
 * reopen that decision first.
 *
 * Each rule is an exhaustive match with no default, so a new case is an error
 * at the first match that reaches it, and the enum test names the mapping it
 * expects for each case. The resolver asks these rules and never compares
 * strings, which is what keeps the swipe, the strip and the sheet from
 * disagreeing about what an action is called or where it goes.
 *
 * `None` is the value a direction takes when it does nothing. It has no route,
 * on purpose: a direction set to none is inert on every row, and asking for its
 * route throws (see requireRoute()) rather than returning a URL that does not
 * exist.
 */
enum SwipeAction: string
{
    /** Mark the row read, or unread when it is read. One toggle, one route. */
    case ReadUnread = 'read_unread';

    /** File the row out of the inbox. Its Undo toast is the server's, not this enum's. */
    case Archive = 'archive';

    /** Star or unstar. The route toggles on the server, so the client only posts. */
    case Star = 'star';

    /** The direction does nothing. Inert on every row, and deliberately without a route. */
    case None = 'none';

    /**
     * The status route the action posts to, or null for None. The route names
     * are the ones ThreadStatusController declares; nothing here is a URL.
     */
    public function route(): ?string
    {
        return match ($this) {
            self::ReadUnread => 'app_status_mark_read',
            self::Archive    => 'app_status_archive',
            self::Star       => 'app_status_star',
            self::None       => null,
        };
    }

    /**
     * The route, for a caller that has no use for an inert action. Throws for
     * None, naming it, so an inert direction that reaches a path expecting a
     * route fails loudly instead of posting to nowhere.
     */
    public function requireRoute(): string
    {
        return $this->route() ?? throw new LogicException(sprintf(
            'Swipe action "%s" has no route: it is inert by design, so it cannot be posted.',
            $this->value,
        ));
    }

    /**
     * Whether pressing it twice undoes it. Read and star do; archive does not,
     * because it leaves the list and comes back only through its Undo or the
     * Archive folder.
     */
    public function toggles(): bool
    {
        return match ($this) {
            self::ReadUnread => true,
            self::Star       => true,
            self::Archive    => false,
            self::None       => false,
        };
    }

    /**
     * The translation key for the revealed button, given the row's state. The
     * read label names what pressing it will do, which is the opposite of what
     * the row is, and the star label does the same, so both take the state.
     */
    public function labelKey(bool $unread, bool $starred): ?string
    {
        return match ($this) {
            self::ReadUnread => $unread ? 'thread_row.actions.mark_read' : 'thread_row.actions.mark_unread',
            self::Archive    => 'thread_row.actions.archive',
            self::Star       => $starred ? 'thread_row.unstar' : 'thread_row.star',
            self::None       => null,
        };
    }

    /**
     * The Font Awesome classes for the revealed button, drawn the way the row's
     * strip draws the same action: an open envelope to mark read, a closed one
     * to mark unread, and a solid star only once starred.
     */
    public function icon(bool $unread, bool $starred): ?string
    {
        return match ($this) {
            self::ReadUnread => $unread ? 'fa-solid fa-envelope-open' : 'fa-solid fa-envelope',
            self::Archive    => 'fa-solid fa-box-archive',
            self::Star       => $starred ? 'fa-solid fa-star' : 'fa-regular fa-star',
            self::None       => null,
        };
    }

    /**
     * Whether the row's strip offers it only on ordinary mail: not a draft, and
     * not in the archive, the bin or spam. Read and archive are, and star is on
     * every row. This is the strip's own rule, moved here so the strip, the
     * swipe and the sheet share one answer (see SwipeActionResolver).
     */
    public function needsOrdinaryMail(): bool
    {
        return match ($this) {
            self::ReadUnread => true,
            self::Archive    => true,
            self::Star       => false,
            self::None       => false,
        };
    }

    /**
     * The JSON body the route expects. Only mark read carries one: its route
     * reads `read`, which is true when the row is unread (pressing it reads it).
     *
     * @return array<string, bool>
     */
    public function requestBody(bool $unread): array
    {
        return match ($this) {
            self::ReadUnread => ['read' => $unread],
            self::Archive    => [],
            self::Star       => [],
            self::None       => [],
        };
    }
}
