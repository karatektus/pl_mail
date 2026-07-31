<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * What an extracted event is about.
 *
 * Null on the entity means "not extracted" — a hand-made event has no kind. So
 * this doubles as the filter behind "Happening Soon", which is a time-windowed
 * query for occurrences whose event has a kind at all, rather than a second
 * table holding the same rows twice.
 *
 * The values line up with the schema.org types the extractor reads, because
 * that is where most of them will come from.
 */
enum ExtractionKind: string
{
    case Meeting  = 'meeting';
    case Delivery = 'delivery';
    case Flight   = 'flight';
    case Train    = 'train';
    case Lodging  = 'lodging';
    case Dining   = 'dining';
    case Rental   = 'rental';
    case Ticket   = 'ticket';
    case Order    = 'order';
    case Call     = 'call';

    /** Font Awesome icon, so a card or a chip can render without a match arm. */
    public function icon(): string
    {
        return match ($this) {
            self::Meeting  => 'fa-solid fa-users',
            self::Delivery => 'fa-solid fa-box',
            self::Flight   => 'fa-solid fa-plane',
            self::Train    => 'fa-solid fa-train',
            self::Lodging  => 'fa-solid fa-bed',
            self::Dining   => 'fa-solid fa-utensils',
            self::Rental   => 'fa-solid fa-car',
            self::Ticket   => 'fa-solid fa-ticket',
            self::Order    => 'fa-solid fa-bag-shopping',
            self::Call     => 'fa-solid fa-phone',
        };
    }

    /** Translation key for the user-facing name. */
    public function transKey(): string
    {
        return 'calendar.kind.' . $this->value;
    }
}
