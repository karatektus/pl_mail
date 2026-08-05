<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * How far a shared link's window reaches, and from when.
 *
 * Two shapes, because the two links people actually make are different things.
 * "Here is my availability for the next fortnight" is a window that moves with
 * today and stays useful for as long as the link lives; "here is my diary for
 * the conference" is two fixed dates and must not creep forward into the weeks
 * after it. Expressing the second as a rolling window would mean re-editing the
 * link every day, and the first as a fixed range would mean a link that quietly
 * shows an empty calendar a fortnight after it was sent.
 *
 * An enum rather than "rolling_days is null means fixed", because the columns
 * for both shapes exist on every row and the null-means-something spelling
 * makes a half-filled row — dates set AND a day count set — a state nobody can
 * read an intent out of. This column is the intent; the others are its
 * parameters.
 */
enum ShareWindow: string
{
    /** Today, forward, by the link's day count. */
    case Rolling = 'rolling';

    /** Two dates the owner named, unchanging. */
    case Fixed = 'fixed';

    /**
     * Whether the link's start and end dates are the ones that matter.
     *
     * Exhaustive with no default: a third shape — "this month", "the next
     * quarter" — would need the form, the validation and the reader to agree
     * about which columns it reads, and a fallthrough here would make it agree
     * silently with whichever branch came last.
     */
    public function usesDates(): bool
    {
        return match ($this) {
            self::Rolling => false,
            self::Fixed   => true,
        };
    }

    /** Translation key for the radio label. */
    public function transKey(): string
    {
        return 'calendar.share.window.' . $this->value;
    }
}
