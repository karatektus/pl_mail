<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * What a calendar is for, which decides where things land without the user
 * having to file them.
 *
 * Account is the load-bearing one: the todo asks that an event extracted from a
 * message belong to "the calendar the email came from", so every mail account
 * gets one of these at creation and it is the default target for anything
 * extracted from that account's mail.
 */
enum CalendarRole: string
{
    /** The user's own calendar. Exactly one per user, never deleted. */
    case Default = 'default';

    /** Bound to a mail account; the default home for its extracted events. */
    case Account = 'account';

    /** A user-made calendar with no special meaning. */
    case Custom = 'custom';

    /** Mirrors a remote calendar over CalDAV or a provider API. */
    case Remote = 'remote';

    /**
     * Whether deleting it would leave the user without somewhere to put an
     * event. The two provisioned roles are re-created rather than mourned, so
     * blocking the delete is kinder than silently resurrecting one.
     */
    public function isDeletable(): bool
    {
        return match ($this) {
            self::Default, self::Account => false,
            self::Custom, self::Remote   => true,
        };
    }
}
