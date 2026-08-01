<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction\StructuredData;

use App\Domain\Enum\Calendar\ExtractionKind;

/**
 * A coach journey.
 *
 * The kind is the awkward part. ExtractionKind has Flight and Train and no bus,
 * and the enum is what picks the icon a user actually sees — so a coach filed
 * as Train shows a train glyph on a FlixBus booking, which reads as a bug
 * rather than an approximation. Ticket is vague but never wrong, and vague is
 * the better failure for something the user is looking at.
 *
 * Adding a Bus case would be the honest fix. It is not free: the icon table and
 * a translation key come with it, and neither belongs in the same change as the
 * extractor that first needed them.
 */
final readonly class BusReservationMapper implements StructuredDataMapperInterface
{
    private const int NOMINAL_MINUTES = 120;

    public function types(): array
    {
        return ['BusReservation'];
    }

    /**
     * @return list<MappedEvent>
     */
    public function map(Node $node): array
    {
        $trip = $node->child('reservationFor');

        if (null === $trip) {
            return [];
        }

        $departs = $trip->moment('departureTime');

        if (null === $departs) {
            return [];
        }

        $arrives = $trip->moment('arrivalTime');
        $endsAt  = null !== $arrives && $arrives->at > $departs->at
            ? $arrives->at
            : $departs->at->modify(sprintf('+%d minutes', self::NOMINAL_MINUTES));

        $number    = Node::join([$trip->string('busName'), $trip->string('busNumber')]);
        $from      = $trip->child('departureBusStop');
        $to        = $trip->child('arrivalBusStop');
        $reference = $node->string('reservationNumber');

        $route = Node::join([$from?->string('name'), $to?->string('name')], ' → ');

        return [new MappedEvent(
            type:     'BusReservation',
            identity: Node::join([$reference, $number, $departs->at->format('Y-m-d')], '/') ?? '',
            startsAt: $departs->at,
            endsAt:   $endsAt,
            kind:     ExtractionKind::Ticket,
            title:    (string) Node::join([$number, $route]),
            location: $from?->locationText(),
            description: null === $reference ? null : '#' . $reference,
        )];
    }
}
