<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction\StructuredData;

use App\Domain\Enum\Calendar\ExtractionKind;

/**
 * A table booked at a restaurant.
 *
 * The shape catches people out: startTime and endTime sit on the RESERVATION,
 * not on the FoodEstablishment it is for. That is correct — a restaurant does
 * not have a start time, a booking does — but it is the opposite of
 * FlightReservation, where the times belong to the Flight, and reading it the
 * other way round yields nothing at all rather than an obvious error.
 *
 * endTime is almost never sent, because a restaurant is booking a table and not
 * a departure, so in practice this is always the nominal length.
 */
final readonly class FoodEstablishmentReservationMapper implements StructuredDataMapperInterface
{
    private const int NOMINAL_MINUTES = 120;

    public function types(): array
    {
        return ['FoodEstablishmentReservation'];
    }

    /**
     * @return list<MappedEvent>
     */
    public function map(Node $node): array
    {
        $starts = $node->moment('startTime');

        if (null === $starts) {
            return [];
        }

        $ends   = $node->moment('endTime');
        $endsAt = null !== $ends && $ends->at > $starts->at
            ? $ends->at
            : $starts->at->modify(sprintf('+%d minutes', self::NOMINAL_MINUTES));

        $venue     = $node->child('reservationFor');
        $reference = $node->string('reservationNumber');

        return [new MappedEvent(
            type:     'FoodEstablishmentReservation',
            identity: (string) ($reference ?? Node::join([$venue?->string('name'), $starts->at->format('Y-m-d\TH:i')], '/')),
            startsAt: $starts->at,
            endsAt:   $endsAt,
            kind:     ExtractionKind::Dining,
            title:    (string) $venue?->label(),
            location: $venue?->locationText(),
            description: Node::join([
                null === $reference ? null : '#' . $reference,
                $venue?->string('telephone'),
            ], "\n"),
        )];
    }
}
