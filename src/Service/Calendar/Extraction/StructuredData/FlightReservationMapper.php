<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction\StructuredData;

use App\Domain\Enum\Calendar\ExtractionKind;

/**
 * A flight leg.
 *
 * The identity here is the one thing about this whole extractor that cannot be
 * taken from the specification, because schema.org and the airline industry
 * disagree about what a reservation is. A return trip or a connection is sent
 * as several FlightReservation objects that all carry the SAME
 * reservationNumber — the PNR is the booking, not the leg. Keyed on the PNR
 * alone, an outbound and its return would collide, the runner would keep
 * whichever it saw first, and half of every trip would silently never reach the
 * calendar.
 *
 * So the flight number and the departure date join the key. Both are needed:
 * the flight number alone breaks a daily commuter shuttle booked twice on one
 * PNR, and the date alone breaks a same-day connection. Together they are what
 * a boarding pass is, which is the right granularity for a calendar entry.
 */
final readonly class FlightReservationMapper implements StructuredDataMapperInterface
{
    /**
     * A leg with a departure and no arrival still has to occupy something. Two
     * hours is the median short-haul sector and a great deal better than the
     * zero-length event a missing arrivalTime would otherwise produce.
     */
    private const int NOMINAL_MINUTES = 120;

    public function types(): array
    {
        return ['FlightReservation'];
    }

    /**
     * @return list<MappedEvent>
     */
    public function map(Node $node): array
    {
        $flight = $node->child('reservationFor');

        if (null === $flight) {
            return [];
        }

        $departs = $flight->moment('departureTime');

        if (null === $departs) {
            // No departure is no event. An itinerary with a leg still to be
            // scheduled is a real thing and not something to invent a time for.
            return [];
        }

        $arrives = $flight->moment('arrivalTime');
        $endsAt  = null !== $arrives && $arrives->at > $departs->at
            ? $arrives->at
            : $departs->at->modify(sprintf('+%d minutes', self::NOMINAL_MINUTES));

        $number    = $this->flightNumber($flight);
        $from      = $flight->child('departureAirport');
        $to        = $flight->child('arrivalAirport');
        $reference = $node->string('reservationNumber');

        $route = Node::join(
            [$from?->string('iataCode') ?? $from?->string('name'), $to?->string('iataCode') ?? $to?->string('name')],
            ' → ',
        );

        return [new MappedEvent(
            type:     'FlightReservation',
            identity: Node::join([$reference, $number, $departs->at->format('Y-m-d')], '/') ?? '',
            startsAt: $departs->at,
            endsAt:   $endsAt,
            kind:     ExtractionKind::Flight,
            title:    (string) Node::join([$number, $route]),
            location: $from?->locationText(),
            description: null === $reference ? null : '#' . $reference,
        )];
    }

    /**
     * schema.org says flightNumber includes the airline's IATA code, and about
     * half of the senders that emit it do. The other half put the code in
     * airline.iataCode and the digits in flightNumber, which reads as "435" on
     * a calendar and tells nobody which airline to look for.
     */
    private function flightNumber(Node $flight): ?string
    {
        $number = $flight->string('flightNumber');

        if (null === $number) {
            return null;
        }

        $carrier = $flight->child('airline')?->string('iataCode');

        if (null === $carrier || 1 === preg_match('/^[A-Za-z]/', $number)) {
            return $number;
        }

        return $carrier . $number;
    }
}
