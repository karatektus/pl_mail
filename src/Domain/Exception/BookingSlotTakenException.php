<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * The slot was free when the page was drawn and is not now.
 *
 * Raised from exactly one place — BookingService, when Postgres refuses the
 * insert that uniq_calendar_booking_page_start guards — and it is the only
 * honest way to report a lost race. Everything before it is a read, and a read
 * cannot promise that what it saw is still true by the time the write happens.
 *
 * The caller's response is to re-offer the list rather than to re-render the
 * form: the slot is gone, so a form pointing at it can only fail again.
 *
 * Note what this is NOT. It is not "the slot was never on offer" — that is a
 * refusal, because it means the posted instant did not match any slot the page
 * generates, which is a crafted or stale request rather than a race.
 */
final class BookingSlotTakenException extends BookingException
{
}
