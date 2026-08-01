<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction\StructuredData;

/**
 * An order, which reaches the calendar only through the parcels it is shipping.
 *
 * An Order carries no date a calendar can use. orderDate is when the user
 * pressed buy, which is in the past by the time the mail arrives; orderStatus
 * is a state and not a moment. Everything worth showing is inside
 * orderDelivery — which is why this class exists at all, since the retailers
 * that emit the richest markup (Amazon among them) wrap the ParcelDelivery in
 * an Order rather than sending one at the top level.
 *
 * An order may ship in several parcels, so orderDelivery is read as a list. One
 * event each, each with its own dedup key, because three boxes arriving on
 * three days is three answers to "what is turning up this week".
 *
 * The events it produces are Deliveries, not Orders. ExtractionKind::Order
 * therefore goes unused by this extractor, and that is the honest outcome: an
 * order with no delivery date is not something to put on a calendar, and an
 * order with one is a delivery.
 *
 * Delegates to ParcelDeliveryMapper rather than repeating it, so that the
 * shipping notification that arrives two days later — a bare ParcelDelivery
 * with the same carrier and tracking number — derives the same identity and
 * updates this event instead of creating a second one.
 */
final readonly class OrderMapper implements StructuredDataMapperInterface
{
    public function __construct(
        private ParcelDeliveryMapper $parcels,
    ) {
    }

    public function types(): array
    {
        return ['Order'];
    }

    /**
     * @return list<MappedEvent>
     */
    public function map(Node $node): array
    {
        $orderNumber = $node->string('orderNumber');
        $merchant    = $node->child('merchant')?->string('name')
            ?? $node->child('seller')?->string('name')
            ?? $node->child('broker')?->string('name');

        $events = [];

        foreach ($node->children('orderDelivery') as $delivery) {
            foreach ($this->parcels->fromDelivery($delivery, $orderNumber, $merchant) as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }
}
