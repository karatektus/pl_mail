<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction\StructuredData;

use DateTimeImmutable;

/**
 * A schema.org timestamp together with whether it named a time of day.
 *
 * Keeping the two apart is the whole point. "2026-08-05" and
 * "2026-08-05T00:00:00Z" decode to the same instant and mean entirely
 * different things: the first is a courier saying "some time on Wednesday",
 * the second is a booking at midnight. A DateTimeImmutable alone cannot tell
 * them apart, so every date-only delivery would land at 00:00 and the calendar
 * would show a parcel as a one-minute appointment in the middle of the night.
 */
final readonly class Moment
{
    public function __construct(
        public DateTimeImmutable $at,
        /** True when the source gave a bare date, so only the day is known. */
        public bool              $dateOnly,
    ) {
    }
}
