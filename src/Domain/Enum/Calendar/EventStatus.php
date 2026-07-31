<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * JSCalendar "status" (RFC 8984 §4.4.4), which is also iCalendar's STATUS.
 *
 * Cancelled is a state, not a delete. A cancelled meeting stays on the calendar
 * struck through, because "was this called off or did I imagine it?" is a
 * question the calendar should answer — and because deleting the row would
 * fight a user who wants it back.
 */
enum EventStatus: string
{
    case Confirmed = 'confirmed';
    case Tentative = 'tentative';
    case Cancelled = 'cancelled';
}
