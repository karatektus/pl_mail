<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * Where one lookup in `sender_picture` stands (#639).
 *
 * `Missing` and `Failed` are different answers and are kept apart on purpose.
 * Missing is the far end saying "there is no picture" — a Gravatar 404, a
 * domain with no BIMI record — which is the common case and is believed for a
 * fortnight. Failed is nobody answering — a timeout, DNS that does not
 * resolve — which says nothing about whether a picture exists and is asked
 * again the next day. Folding them together would either re-ask every miss
 * daily or leave a sender whose site was down for an afternoon without a
 * picture for two weeks.
 */
enum SenderPictureStatus: string
{
    /** Queued, or being looked up now. */
    case Pending = 'pending';

    /** A picture was stored; `file` names it. */
    case Found = 'found';

    /** The far end answered, and there is no picture. */
    case Missing = 'missing';

    /** Nobody answered usefully. Asked again sooner than a miss. */
    case Failed = 'failed';

    /**
     * How long an answer is believed before it is asked again, in days.
     *
     * A found picture is re-checked monthly: people change them, and a
     * re-check that finds the same bytes costs one request and no write.
     * Pending is never "due" by age alone — see SenderPictureRepository's
     * claim, which treats a pending row as stuck only after an hour.
     */
    public function refreshAfterDays(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Found   => 30,
            self::Missing => 14,
            self::Failed  => 1,
        };
    }
}
