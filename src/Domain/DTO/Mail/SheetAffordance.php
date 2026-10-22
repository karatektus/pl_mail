<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Domain\Enum\Mail\SheetEntry;
use App\Domain\Enum\Mail\SwipeAction;

/**
 * One entry of the long-press sheet on one row, as the server has decided it.
 *
 * Built only by SwipeActionResolver::sheet(), and only for an entry offered on
 * this row, so an entry the strip would not draw has no affordance at all. The
 * entry is either a SwipeAction (read/unread, archive, star, which the sheet
 * shares with the swipe) or a SheetEntry (everything else).
 *
 * `url` is null for the three client-only entries. `body` is the JSON the route
 * reads, empty when it reads nothing; the sheet's snooze adds its own wake time.
 */
final readonly class SheetAffordance
{
    /**
     * @param array<string, bool> $body the JSON the route reads, empty when it reads nothing
     */
    public function __construct(
        public SwipeAction|SheetEntry $entry,
        public ?string $url,
        public array $body,
    ) {
    }
}
