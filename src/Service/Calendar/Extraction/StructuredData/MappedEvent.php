<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction\StructuredData;

use App\Domain\Enum\Calendar\ExtractionKind;
use DateTimeImmutable;

/**
 * What one mapper made of one node, before it is an ExtractedEvent.
 *
 * The mappers deliberately do not build ExtractedEvents. Identity, the dedup
 * key, cancellation, confidence and the verbatim payload are the same rules for
 * all nine types, and nine copies of them is nine places for the key formula to
 * drift — which is precisely the failure CalendarEvent::$dedupKeyVersion exists
 * to make survivable and which is much better not to have. So a mapper answers
 * only the questions that differ per type, and StructuredDataEventExtractor
 * turns the answer into a claim.
 *
 * $type and $identity are what the key is derived from, and they are separate
 * from the mapper's own class on purpose: an Order that carries a tracked
 * parcel produces a ParcelDelivery identity, so the shipping notification that
 * follows it lands on the same event rather than beside it.
 */
final readonly class MappedEvent
{
    public function __construct(
        /** The schema.org term the identity belongs to, which is not always the node's own type. */
        public string            $type,
        /** What distinguishes this booking from every other of its type by the same sender. */
        public string            $identity,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public ExtractionKind    $kind,
        public string            $title,
        public ?string           $location = null,
        public bool              $isAllDay = false,
        public ?string           $description = null,
    ) {
    }
}
