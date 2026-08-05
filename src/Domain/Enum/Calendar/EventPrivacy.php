<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * JSCalendar "privacy" (RFC 8984 §4.4.3).
 *
 * Unused by the calendar itself — the owner sees everything they own. It exists
 * from the start because the shared-calendar feature turns it into an access
 * decision, and retrofitting a privacy default onto rows that predate it means
 * guessing what the user meant. Secret must never leave the account; Private
 * shows as busy with no detail.
 */
enum EventPrivacy: string
{
    case Public  = 'public';
    case Private = 'private';
    case Secret  = 'secret';

    /**
     * Whether an event carrying this may appear on a shared link at all, even
     * as an anonymous busy block.
     *
     * Secret answers false, which is the promise the docblock above makes and
     * the reason this enum was written before anything used it. A secret event
     * is not "busy with no detail" — its existence is the detail, and a block
     * appearing on a Tuesday afternoon is what somebody reading the link would
     * act on.
     *
     * The cost is honest and is accepted: a shared calendar containing secret
     * events says the owner is free at hours they are not, so the link can be
     * used to book over one. That is the trade the word "secret" asks for, and
     * the alternative — showing it as busy — makes the setting mean nothing
     * different from Private.
     *
     * Exhaustive with no default, because a fourth privacy would otherwise
     * inherit whichever answer happened to be last.
     */
    public function isShareable(): bool
    {
        return match ($this) {
            self::Public,
            self::Private => true,
            self::Secret  => false,
        };
    }

    /**
     * Whether a shared link may show this event's title, location, description
     * or participants — supposing its own checkboxes allow it.
     *
     * The event's privacy is the ceiling and the link's ticks are the floor,
     * and this is the ceiling half. A user who marked a meeting Private meant
     * "the fact that I am busy is fine, the subject is not", and no per-link
     * checkbox may override that: the link is a decision about an audience, the
     * privacy is a decision about one meeting, and the narrower one has to win
     * or the wider one is a way to undo it in bulk.
     */
    public function mayRevealDetail(): bool
    {
        return match ($this) {
            self::Public  => true,
            self::Private,
            self::Secret  => false,
        };
    }
}
