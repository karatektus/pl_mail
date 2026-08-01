<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction\StructuredData;

use App\Domain\Enum\Calendar\ExtractionKind;

/**
 * A parcel on its way, as an all-day event on the day it should arrive.
 *
 * An event and not a task, deliberately. A task needs a task model, a done
 * state, a view that shows it and a decision about what "overdue" means for
 * something nobody controls — and none of that helps with the actual question,
 * which is "is anything arriving while I am out on Thursday?". That is a
 * calendar question, and an all-day event answers it in the view the user is
 * already looking at.
 *
 * All-day is the normal case rather than a fallback. Couriers commit to a day
 * and not an hour, so expectedArrivalFrom/Until are usually bare dates; when a
 * carrier does give a delivery window, the times are honoured and the event is
 * that window.
 *
 * Identity is the carrier and the tracking number, which is what makes the
 * shipping notification and the "out for delivery" mail that follows it the
 * same row rather than two. Where there is no tracking number the order number
 * stands in — see fromDelivery().
 */
final readonly class ParcelDeliveryMapper implements StructuredDataMapperInterface
{
    public function types(): array
    {
        return ['ParcelDelivery'];
    }

    /**
     * @return list<MappedEvent>
     */
    public function map(Node $node): array
    {
        // A standalone ParcelDelivery points back at its order; nested in one
        // it does not, which is why OrderMapper passes those values in.
        $order = $node->child('partOfOrder');

        return $this->fromDelivery(
            $node,
            $order?->string('orderNumber'),
            $order?->child('merchant')?->string('name') ?? $order?->child('seller')?->string('name'),
        );
    }

    /**
     * @param ?string $orderNumber the order this parcel belongs to, when the caller knows it
     * @param ?string $merchant    who sold it, which is what a user recognises the parcel by
     *
     * @return list<MappedEvent>
     */
    public function fromDelivery(Node $delivery, ?string $orderNumber, ?string $merchant): array
    {
        $from  = $delivery->moment('expectedArrivalFrom');
        $until = $delivery->moment('expectedArrivalUntil');
        $start = $from ?? $until;

        if (null === $start) {
            // A shipment with no expected date is not a calendar entry. It is
            // still perfectly good mail, and the tracking link is in it.
            return [];
        }

        $end     = null !== $from && null !== $until ? $until : $start;
        $allDay  = true === $start->dateOnly && true === $end->dateOnly;
        $carrier = $delivery->child('carrier')?->string('name')
            ?? $delivery->child('carrier')?->string('alternateName');
        $tracking = $delivery->string('trackingNumber');

        // A calendar end is exclusive, so a parcel due on the 5th has to run to
        // the 6th or the day it arrives is not the day it is shown on.
        $endsAt = match (true) {
            true === $allDay      => $end->at->modify('+1 day'),
            $end->at > $start->at => $end->at,
            default               => $start->at->modify('+1 hour'),
        };

        // The type is part of the dedup key, so a tracked parcel keeps the
        // ParcelDelivery identity wherever it was found — inside an order
        // confirmation or standing alone in the shipping notice that follows.
        [$type, $identity] = match (true) {
            null !== $tracking    => ['ParcelDelivery', (string) Node::join([$carrier, $tracking], '/')],
            // The order number alone, not the order number and the date: a
            // revised estimate for the same untracked parcel is the commoner
            // event by far, and it has to land on the existing row. The cost is
            // that an order shipping several untracked parcels collapses to one
            // event — which is a great deal better than a re-estimate silently
            // becoming a second delivery every time the retailer mails.
            null !== $orderNumber => ['Order', $orderNumber],
            default               => ['', ''],
        };

        if ('' === $identity) {
            // Neither a tracking number nor an order number means nothing to
            // recognise this parcel by later, so every mail about it would be a
            // new event.
            return [];
        }

        return [new MappedEvent(
            type:     $type,
            identity: $identity,
            startsAt: $start->at,
            endsAt:   $endsAt,
            kind:     ExtractionKind::Delivery,
            title:    (string) Node::join([
                $merchant ?? $carrier,
                null === $orderNumber ? $tracking : '#' . $orderNumber,
            ]),
            location: $delivery->child('deliveryAddress')?->addressText(),
            isAllDay: $allDay,
            description: Node::join([
                Node::join([$carrier, $tracking]),
                $delivery->url('trackingUrl'),
            ], "\n"),
        )];
    }
}
