<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction\StructuredData;

use App\Domain\Enum\Calendar\ExtractionKind;

/**
 * A train leg.
 *
 * Same identity problem as a flight and for the same reason: one booking
 * reference covers an outbound, a return and every change of train in between,
 * so the reference alone would collapse a whole journey into one event. The
 * train number and the departure date join the key.
 *
 * Kept separate from the flight mapper rather than sharing a "trip" base,
 * because the ten lines they have in common are the trivial ones and the
 * differences are the ones worth reading — departureStation against
 * departureAirport, trainName against airline, a platform instead of a
 * terminal. A shared parent parameterised by field name would hide all of that
 * behind a table.
 */
final readonly class TrainReservationMapper implements StructuredDataMapperInterface
{
    /** A train with no arrivalTime is more often a regional hop than a sleeper. */
    private const int NOMINAL_MINUTES = 60;

    public function types(): array
    {
        return ['TrainReservation'];
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

        $number    = Node::join([$trip->string('trainName'), $trip->string('trainNumber')]);
        $from      = $trip->child('departureStation');
        $to        = $trip->child('arrivalStation');
        $reference = $node->string('reservationNumber');

        $route = Node::join([$from?->string('name'), $to?->string('name')], ' → ');

        return [new MappedEvent(
            type:     'TrainReservation',
            identity: Node::join([$reference, $number, $departs->at->format('Y-m-d')], '/') ?? '',
            startsAt: $departs->at,
            endsAt:   $endsAt,
            kind:     ExtractionKind::Train,
            title:    (string) Node::join([$number, $route]),
            location: $from?->locationText(),
            // departurePlatform is deliberately not carried across. It is
            // assigned on the day, so the value in a booking confirmation is
            // either absent or stale, and a stale platform is worse than none.
            description: null === $reference ? null : '#' . $reference,
        )];
    }
}
