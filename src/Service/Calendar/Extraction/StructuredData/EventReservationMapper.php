<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction\StructuredData;

use App\Domain\Enum\Calendar\ExtractionKind;

/**
 * A ticket to something — a concert, a match, a conference.
 *
 * The only type here whose reservationFor is itself an Event, which means the
 * dates are startDate and endDate rather than the start/end pair every other
 * reservation uses, and the name is the event's rather than the venue's.
 *
 * doorTime is deliberately ignored. When a venue opens an hour before the band
 * plays, the thing on the calendar is the gig; taking the earlier of the two
 * for startsAt would quietly mean nobody's calendar agrees with their ticket,
 * and putting it in the description means rendering a bare time with no zone,
 * since a schema.org offset names an offset and not a zone.
 */
final readonly class EventReservationMapper implements StructuredDataMapperInterface
{
    /** Long enough for a film or a match, which is what an untimed ticket usually is. */
    private const int NOMINAL_MINUTES = 180;

    public function types(): array
    {
        return ['EventReservation'];
    }

    /**
     * @return list<MappedEvent>
     */
    public function map(Node $node): array
    {
        $event = $node->child('reservationFor');

        if (null === $event) {
            return [];
        }

        $starts = $event->moment('startDate');

        if (null === $starts) {
            return [];
        }

        $ends   = $event->moment('endDate');
        $allDay = true === $starts->dateOnly && (null === $ends || true === $ends->dateOnly);

        $endsAt = match (true) {
            true === $allDay && null !== $ends => $ends->at->modify('+1 day'),
            true === $allDay                   => $starts->at->modify('+1 day'),
            null !== $ends && $ends->at > $starts->at => $ends->at,
            default => $starts->at->modify(sprintf('+%d minutes', self::NOMINAL_MINUTES)),
        };

        $name      = $event->string('name');
        $venue     = $event->child('location');
        $reference = $node->string('reservationNumber');

        return [new MappedEvent(
            type:     'EventReservation',
            identity: (string) ($reference ?? Node::join([$name, $starts->at->format('Y-m-d')], '/')),
            startsAt: $starts->at,
            endsAt:   $endsAt,
            kind:     ExtractionKind::Ticket,
            title:    (string) ($name ?? $venue?->label()),
            location: $venue?->locationText(),
            isAllDay: $allDay,
            description: null === $reference ? null : '#' . $reference,
        )];
    }
}
