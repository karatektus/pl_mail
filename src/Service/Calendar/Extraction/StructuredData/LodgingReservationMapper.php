<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction\StructuredData;

use App\Domain\Enum\Calendar\ExtractionKind;

/**
 * A hotel stay, as one event from check-in to check-out.
 *
 * One event rather than two. A separate check-in and check-out entry is what a
 * travel app does, and it is wrong for a calendar: the days between them are
 * days the user is away, and an eight-day trip that shows as two half-hour
 * appointments tells nobody looking at the month view that the week is gone.
 *
 * checkinTime and checkoutTime are the fields, despite naming a date about as
 * often as a time — a hotel that has not decided your check-in hour still knows
 * which day you arrive. When both are bare dates the stay becomes an all-day
 * span, which is exactly what it is.
 */
final readonly class LodgingReservationMapper implements StructuredDataMapperInterface
{
    public function types(): array
    {
        return ['LodgingReservation'];
    }

    /**
     * @return list<MappedEvent>
     */
    public function map(Node $node): array
    {
        $checkin = $node->moment('checkinTime');

        if (null === $checkin) {
            return [];
        }

        $checkout = $node->moment('checkoutTime');
        $allDay   = true === $checkin->dateOnly && (null === $checkout || true === $checkout->dateOnly);

        // A calendar end is exclusive, so an all-day stay has to run to the
        // midnight AFTER the check-out day or the last night is not shown. A
        // one-night stay with no checkoutTime is the floor.
        $endsAt = match (true) {
            null === $checkout        => $checkin->at->modify('+1 day'),
            true === $allDay          => $checkout->at->modify('+1 day'),
            $checkout->at > $checkin->at => $checkout->at,
            default                   => $checkin->at->modify('+1 day'),
        };

        $hotel     = $node->child('reservationFor');
        $reference = $node->string('reservationNumber');

        return [new MappedEvent(
            type:     'LodgingReservation',
            identity: (string) ($reference ?? Node::join([$hotel?->string('name'), $checkin->at->format('Y-m-d')], '/')),
            startsAt: $checkin->at,
            endsAt:   $endsAt,
            kind:     ExtractionKind::Lodging,
            title:    (string) $hotel?->label(),
            location: $hotel?->locationText(),
            isAllDay: $allDay,
            description: Node::join([
                null === $reference ? null : '#' . $reference,
                $hotel?->string('telephone'),
            ], "\n"),
        )];
    }
}
