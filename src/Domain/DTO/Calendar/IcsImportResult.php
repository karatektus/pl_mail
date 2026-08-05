<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

/**
 * What importing one .ics actually did.
 *
 * Four counts rather than "n events imported", because three of the four are
 * things a person will otherwise read as a failure. A file of forty events that
 * imports as "0 imported" is indistinguishable from a broken import unless the
 * screen also says that all forty were already there — which is the normal
 * outcome of importing the same file twice, and of importing an export of the
 * calendar you are importing into.
 *
 * $alreadyElsewhere is the one that could not be guessed from the calendar
 * afterwards, and the one the dedup rule exists for: the meeting is on this
 * user's calendar already, under the organiser's own UID, on a *different* list
 * — extracted from its invitation, or mirrored from a connected calendar. It is
 * not imported again, and saying so is the difference between a rule that looks
 * like a bug and a rule that looks like a rule.
 *
 * $skipped counts components this could make nothing of: a VEVENT with no
 * DTSTART has nowhere to be drawn, and a file whose every entry is one of those
 * is a file the user needs told about rather than a silent no-op.
 */
final readonly class IcsImportResult
{
    public function __construct(
        public int $imported = 0,
        public int $updated = 0,
        public int $alreadyElsewhere = 0,
        public int $skipped = 0,
    ) {
    }

    /**
     * Whether the calendar looks any different afterwards.
     *
     * Stays a method: it is a claim about four numbers together, and the
     * interesting part is which two are excluded — a file that only matched
     * events already present changed nothing, however many of them there were.
     */
    public function changedAnything(): bool
    {
        return 0 < $this->imported || 0 < $this->updated;
    }

    /** How many components in the file were understood at all. */
    public function read(): int
    {
        return $this->imported + $this->updated + $this->alreadyElsewhere + $this->skipped;
    }
}
