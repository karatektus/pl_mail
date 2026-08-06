<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * What a calendar is for, which decides where things land without the user
 * having to file them.
 *
 * Account is not where extracted events go. It was — "the calendar the email
 * came from" — and that split one person's day across as many calendars as they
 * had mailboxes; see ExtractedEventCalendarResolver, which files into the
 * user's Default calendar now. Every mail account still gets one of these at
 * creation, because it is what Account::SETTING_CALENDAR_TARGET points at when
 * somebody does want a mailbox's bookings kept apart, and what a mirrored
 * provider calendar attaches to.
 */
enum CalendarRole: string
{
    /** The user's own calendar. Exactly one per user, never deleted. */
    case Default = 'default';

    /** Bound to a mail account; where its extracted events go if asked for. */
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
