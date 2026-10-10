<?php

declare(strict_types=1);

namespace App\Service\Imap;

/**
 * What became of one page of a folder's import, in terms of what to do next.
 *
 * Four answers because the caller has four things it can do, and a boolean
 * "is there more" would fold together the two that must not be: a page that
 * went through, whose successor should follow at once, and a page that held
 * something back, which is the same messages again and gains nothing from
 * being asked for a second later.
 */
enum ImapImportOutcome
{
    /** The page is in and there is history below it. Ask for the next. */
    case More;

    /** Something would not store and the floor stayed. Ask again, later. */
    case Retry;

    /** Nothing could be done just now — no folder, no plan. Ask again, later. */
    case Waiting;

    /** This folder has no history left. */
    case Finished;

    /** Whether the same folder should be asked again, as opposed to moved on from. */
    public function isUnfinished(): bool
    {
        return match ($this) {
            self::More, self::Retry, self::Waiting => true,
            self::Finished                         => false,
        };
    }

    /** Whether asking again straight away would only repeat what just happened. */
    public function shouldWait(): bool
    {
        return match ($this) {
            self::Retry, self::Waiting   => true,
            self::More, self::Finished   => false,
        };
    }
}
