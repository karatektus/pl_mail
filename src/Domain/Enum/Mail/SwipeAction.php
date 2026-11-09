<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

use LogicException;

/**
 * What a swipe across a mail row does, once it commits.
 *
 * Trash is a case since #527. It was left out at first because a swipe commits
 * on a full drag or a fling with no tap in front of it, and a trashed row had
 * no Undo: a slipped thumb would have deleted mail with nothing to take it
 * back. The trash route now answers with the same Undo toast archive does
 * (ThreadStatusController::trash(), StatusUndoService), so a trash swipe is as
 * recoverable as an archive swipe, and it is a choice, never a default. Purge
 * is still not a swipe action and must not become one: it is the one route that
 * destroys mail, and nothing can undo it.
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

    /**
     * Move the row to the bin. Its Undo toast is the server's, as archive's is.
     * Never purge: a swipe must not end in the one action nothing undoes.
     */
    case Trash = 'trash';

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
            self::Trash      => 'app_status_trash',
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
     * Whether pressing it twice undoes it. Read and star do; archive and trash
     * do not, because the row leaves the list and comes back only through its
     * Undo, or from the Archive folder or the bin.
     */
    public function toggles(): bool
    {
        return match ($this) {
            self::ReadUnread => true,
            self::Star       => true,
            self::Archive    => false,
            self::Trash      => false,
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
            self::Trash      => 'thread_row.actions.delete',
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
            self::Trash      => 'fa-solid fa-trash',
            self::None       => null,
        };
    }

    /**
     * Whether the row's strip offers it only on mail in its ordinary place: not
     * in the archive, the bin or spam. Read, archive and trash are, and star is
     * in every place. This is the strip's own rule, moved here so the strip, the
     * swipe and the sheet share one answer (see SwipeActionResolver).
     */
    public function needsOrdinaryPlacement(): bool
    {
        return match ($this) {
            self::ReadUnread => true,
            self::Archive    => true,
            self::Trash      => true,
            self::Star       => false,
            self::None       => false,
        };
    }

    /**
     * Whether the strip withholds it from a draft. Read and archive are withheld;
     * star and trash are on drafts too, as the strip's own buttons are, so a
     * draft can be swiped into the bin like any other row (#527).
     */
    public function needsNotADraft(): bool
    {
        return match ($this) {
            self::ReadUnread => true,
            self::Archive    => true,
            self::Trash      => false,
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
            self::Trash      => [],
            self::None       => [],
        };
    }

    /**
     * Whatever was stored for a direction, read charitably: the settings bag is
     * untyped and may hold a value a hand edit or a restored backup put there.
     * Anything that is not one of the cases above, "purge" among them, reads as
     * the direction's own default.
     */
    public static function fromSetting(mixed $value, self $default): self
    {
        return true === is_string($value) ? self::tryFrom($value) ?? $default : $default;
    }
}
