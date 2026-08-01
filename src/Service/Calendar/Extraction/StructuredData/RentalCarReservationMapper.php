<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction\StructuredData;

use App\Domain\Enum\Calendar\ExtractionKind;

/**
 * A hire car, from pickup to drop-off.
 *
 * One event spanning the hire rather than two appointments, on the same
 * reasoning as a hotel stay: the days in between are days the user has a car,
 * and that is the thing a calendar is being asked. The drop-off deadline is
 * still visible because it is the end of the event.
 *
 * Like the restaurant and unlike the flight, the times and both locations are
 * on the reservation; reservationFor holds only the car.
 */
final readonly class RentalCarReservationMapper implements StructuredDataMapperInterface
{
    public function types(): array
    {
        return ['RentalCarReservation'];
    }

    /**
     * @return list<MappedEvent>
     */
    public function map(Node $node): array
    {
        $pickup = $node->moment('pickupTime');

        if (null === $pickup) {
            return [];
        }

        $dropoff = $node->moment('dropoffTime');
        $allDay  = true === $pickup->dateOnly && (null === $dropoff || true === $dropoff->dateOnly);

        $endsAt = match (true) {
            null === $dropoff            => $pickup->at->modify('+1 day'),
            true === $allDay             => $dropoff->at->modify('+1 day'),
            $dropoff->at > $pickup->at   => $dropoff->at,
            default                      => $pickup->at->modify('+1 day'),
        };

        $car       = $node->child('reservationFor');
        $company   = $car?->child('rentalCompany')?->string('name') ?? $node->child('provider')?->string('name');
        $model     = $car?->string('model') ?? $car?->string('name');
        $brand     = $car?->child('brand')?->string('name') ?? $car?->string('brand');
        $where     = $node->child('pickupLocation');
        $reference = $node->string('reservationNumber');

        return [new MappedEvent(
            type:     'RentalCarReservation',
            identity: (string) ($reference ?? Node::join([$company, $pickup->at->format('Y-m-d')], '/')),
            startsAt: $pickup->at,
            endsAt:   $endsAt,
            kind:     ExtractionKind::Rental,
            title:    (string) Node::join([$company, Node::join([$brand, $model])], ' · '),
            location: $where?->locationText(),
            isAllDay: $allDay,
            description: null === $reference ? null : '#' . $reference,
        )];
    }
}
