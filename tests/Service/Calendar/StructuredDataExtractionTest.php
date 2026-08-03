<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSourceLink;
use App\Entity\Calendar\EventSuppression;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Calendar\EventSuppressionRepository;
use App\Service\Calendar\CalendarProvisioner;
use App\Service\Calendar\EventReconciler;
use App\Service\Calendar\Extraction\EventExtractionRunner;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * schema.org markup in a mail body, from the script tag to a row on a calendar.
 *
 * The fixtures are the point of this file. They are the shapes senders actually
 * emit — an airline that puts the IATA code in a separate field, a retailer that
 * wraps the parcel in an Order, a hotel that gives dates where the vocabulary
 * says times — because the failure mode of this extractor is never "it threw",
 * it is "it read a legal document as if it were a different legal document and
 * put one flight on the calendar instead of two".
 *
 * The hostile cases are not decoration either. A body is written by whoever
 * sent the mail, so malformed JSON, a javascript: URL in a field that gets
 * rendered, and a sender claiming five hundred reservations are all things this
 * has to survive without costing the message.
 */
final class StructuredDataExtractionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private EventExtractionRunner $runner;
    private EventReconciler $reconciler;
    private Account $account;
    private User $user;
    private Calendar $calendar;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->runner     = $container->get(EventExtractionRunner::class);
        $this->reconciler = $container->get(EventReconciler::class);

        $this->connection->beginTransaction();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── Flights ───────────────────────────────────────────────────────────

    public function testAFlightBecomesAnEvent(): void
    {
        $events = $this->ingest($this->html($this->flight('XK7Q2R', 'LH', '400', 'FRA', 'JFK', '2026-09-14T13:25:00+02:00', '2026-09-14T16:10:00-04:00')));

        self::assertCount(1, $events);
        self::assertSame(ExtractionKind::Flight, $events[0]->kind);
        self::assertSame(EventSource::StructuredData, $events[0]->source);
        self::assertSame(90, $events[0]->confidence);
        self::assertSame('LH400 FRA → JFK', $events[0]->title);

        // 13:25+02:00 is 11:25Z; 16:10-04:00 is 20:10Z. An eight-hour crossing,
        // not the two hours the clock faces suggest.
        self::assertSame('2026-09-14 11:25', $events[0]->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-09-14 20:10', $events[0]->endsAt->format('Y-m-d H:i'));
    }

    /**
     * The one that decides whether this extractor is worth having.
     *
     * A return trip is two FlightReservation objects carrying the SAME
     * reservationNumber, because the PNR is the booking and not the leg. Keyed
     * on the PNR alone the runner would keep whichever it saw first and the
     * flight home would never reach the calendar.
     */
    public function testBothLegsOfOneBookingBecomeTwoEvents(): void
    {
        $events = $this->ingest($this->html(
            '[' . $this->flight('XK7Q2R', 'LH', '400', 'FRA', 'JFK', '2026-09-14T13:25:00+02:00', '2026-09-14T16:10:00-04:00')
            . ',' . $this->flight('XK7Q2R', 'LH', '401', 'JFK', 'FRA', '2026-09-21T18:30:00-04:00', '2026-09-22T08:05:00+02:00')
            . ']',
        ));

        self::assertCount(2, $events);
        self::assertSame(['LH400 FRA → JFK', 'LH401 JFK → FRA'], array_map(
            static fn (CalendarEvent $e): ?string => $e->title,
            $events,
        ));
    }

    /**
     * schema.org says flightNumber carries the airline code. Half the senders
     * that emit it put the code in airline.iataCode instead, and "400" on a
     * calendar tells nobody which airline to look for.
     */
    public function testABareFlightNumberIsPrefixedWithTheAirlineCode(): void
    {
        $events = $this->ingest($this->html($this->flight('AA11BB', 'BA', '117', 'LHR', 'JFK', '2026-09-14T08:30:00+01:00', '2026-09-14T11:25:00-04:00')));

        self::assertStringStartsWith('BA117', (string) $events[0]->title);
    }

    /** An airline that already writes the code must not have it doubled. */
    public function testAPrefixedFlightNumberIsLeftAlone(): void
    {
        $json = '{"@context":"https://schema.org","@type":"FlightReservation",'
            . '"reservationNumber":"ZZ9Y8X","reservationStatus":"https://schema.org/ReservationConfirmed",'
            . '"reservationFor":{"@type":"Flight","flightNumber":"KL1234",'
            . '"airline":{"@type":"Airline","iataCode":"KL","name":"KLM"},'
            . '"departureAirport":{"@type":"Airport","iataCode":"AMS"},'
            . '"arrivalAirport":{"@type":"Airport","iataCode":"LHR"},'
            . '"departureTime":"2026-09-14T09:00:00+02:00"}}';

        $events = $this->ingest($this->html($json));

        self::assertSame('KL1234 AMS → LHR', $events[0]->title);

        // No arrivalTime: a nominal length rather than a zero-length event
        // nothing can render.
        self::assertGreaterThan($events[0]->startsAt, $events[0]->endsAt);
    }

    // ── Parcels and orders ────────────────────────────────────────────────

    public function testAParcelWithTrackingBecomesAnAllDayDelivery(): void
    {
        $events = $this->ingest($this->html($this->parcel('DHL', '00340434161094017981', '2026-09-16', '2026-09-16')));

        self::assertCount(1, $events);
        self::assertSame(ExtractionKind::Delivery, $events[0]->kind);
        self::assertTrue($events[0]->isAllDay);
        self::assertNull($events[0]->timeZone);

        // The end is exclusive, so a parcel due on the 16th runs to the 17th or
        // the day it arrives is not the day it is shown on.
        self::assertSame('2026-09-16 00:00', $events[0]->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-09-17 00:00', $events[0]->endsAt->format('Y-m-d H:i'));
    }

    /** A courier that does give a window is telling us something; honour it. */
    public function testADeliveryWindowWithTimesIsNotAllDay(): void
    {
        $json = '{"@context":"https://schema.org","@type":"ParcelDelivery",'
            . '"trackingNumber":"JD0002340991","carrier":{"@type":"Organization","name":"DPD"},'
            . '"expectedArrivalFrom":"2026-09-16T10:00:00+02:00",'
            . '"expectedArrivalUntil":"2026-09-16T12:00:00+02:00"}';

        $events = $this->ingest($this->html($json));

        self::assertFalse($events[0]->isAllDay);
        self::assertSame('2026-09-16 08:00', $events[0]->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-09-16 10:00', $events[0]->endsAt->format('Y-m-d H:i'));
    }

    /** An order with three boxes is three answers to "what is turning up". */
    public function testAnOrderWithSeveralParcelsBecomesSeveralEvents(): void
    {
        $events = $this->ingest($this->html($this->order('114-2938471', 'Amazon', [
            ['UPS', '1Z999AA10123456784', '2026-09-16'],
            ['UPS', '1Z999AA10123456785', '2026-09-18'],
        ])));

        self::assertCount(2, $events);
        self::assertSame([ExtractionKind::Delivery, ExtractionKind::Delivery], array_map(
            static fn (CalendarEvent $e): ?ExtractionKind => $e->kind,
            $events,
        ));
        self::assertSame(['2026-09-16', '2026-09-18'], array_map(
            static fn (CalendarEvent $e): string => $e->startsAt->format('Y-m-d'),
            $events,
        ));
    }

    /**
     * The payoff of keying a parcel on its carrier and tracking number rather
     * than on the order it happened to be announced in: the shipping notice
     * that follows two days later is the same parcel, so it updates the event
     * instead of putting a second one beside it.
     */
    public function testTheShippingNoticeUpdatesTheOrderItCameFrom(): void
    {
        $this->ingest($this->html($this->order('114-2938471', 'Amazon', [
            ['UPS', '1Z999AA10123456784', '2026-09-16'],
        ])));

        $events = $this->ingest($this->html($this->parcel('UPS', '1Z999AA10123456784', '2026-09-15', '2026-09-15')));

        self::assertCount(1, $events);
        self::assertCount(1, $this->allEvents());
        self::assertSame('2026-09-15', $events[0]->startsAt->format('Y-m-d'));
    }

    /** An order with nothing shipping yet has no date, so it is not an event. */
    public function testAnOrderWithNoDeliveryDateProducesNothing(): void
    {
        $json = '{"@context":"https://schema.org","@type":"Order","orderNumber":"114-000",'
            . '"orderStatus":"https://schema.org/OrderProcessing",'
            . '"merchant":{"@type":"Organization","name":"Amazon"},'
            . '"orderDelivery":{"@type":"ParcelDelivery","trackingNumber":"1Z999AA1"}}';

        self::assertSame([], $this->ingest($this->html($json)));
    }

    // ── Stays, tables, tickets, cars ──────────────────────────────────────

    public function testAHotelBookingSpansTheStay(): void
    {
        $events = $this->ingest($this->html($this->lodging('4429318', 'Hotel Adlon Kempinski', '2026-10-02', '2026-10-05')));

        self::assertCount(1, $events);
        self::assertSame(ExtractionKind::Lodging, $events[0]->kind);
        self::assertSame('Hotel Adlon Kempinski', $events[0]->title);
        self::assertStringContainsString('Unter den Linden 77', (string) $events[0]->location);
        self::assertTrue($events[0]->isAllDay);
        self::assertSame('2026-10-02 00:00', $events[0]->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-10-06 00:00', $events[0]->endsAt->format('Y-m-d H:i'));
    }

    public function testARestaurantBookingBecomesADiningEvent(): void
    {
        $json = '{"@context":"https://schema.org","@type":"FoodEstablishmentReservation",'
            . '"reservationNumber":"OT-88213","reservationStatus":"https://schema.org/ReservationConfirmed",'
            . '"partySize":4,"startTime":"2026-09-19T19:30:00+02:00",'
            . '"reservationFor":{"@type":"FoodEstablishment","name":"Nobelhart & Schmutzig",'
            . '"telephone":"+49 30 1234567",'
            . '"address":{"@type":"PostalAddress","streetAddress":"Friedrichstraße 218",'
            . '"addressLocality":"Berlin","postalCode":"10969","addressCountry":"DE"}}}';

        $events = $this->ingest($this->html($json));

        self::assertCount(1, $events);
        self::assertSame(ExtractionKind::Dining, $events[0]->kind);
        self::assertSame('Nobelhart & Schmutzig', $events[0]->title);
        self::assertSame('2026-09-19 17:30', $events[0]->startsAt->format('Y-m-d H:i'));

        // startTime sits on the reservation, not on the FoodEstablishment —
        // the opposite of a flight, and reading it the other way round yields
        // nothing rather than an obvious error.
        self::assertFalse($events[0]->isAllDay);
        self::assertStringContainsString('+49 30 1234567', (string) ($events[0]->jscalendar['description'] ?? ''));
    }

    public function testATicketBecomesAnEventReservation(): void
    {
        $json = '{"@context":"https://schema.org","@type":"EventReservation",'
            . '"reservationNumber":"TK-99120","reservationFor":{"@type":"Event",'
            . '"name":"Berliner Philharmoniker","startDate":"2026-11-03T20:00:00+01:00",'
            . '"doorTime":"2026-11-03T19:00:00+01:00",'
            . '"location":{"@type":"Place","name":"Philharmonie Berlin",'
            . '"address":{"@type":"PostalAddress","streetAddress":"Herbert-von-Karajan-Str. 1","addressLocality":"Berlin"}}}}';

        $events = $this->ingest($this->html($json));

        self::assertSame(ExtractionKind::Ticket, $events[0]->kind);
        self::assertSame('Berliner Philharmoniker', $events[0]->title);

        // The gig, not the doors — the calendar has to agree with the ticket.
        self::assertSame('2026-11-03 19:00', $events[0]->startsAt->format('Y-m-d H:i'));
    }

    public function testAHireCarSpansThePeriodItIsHeldFor(): void
    {
        $json = '{"@context":"https://schema.org","@type":"RentalCarReservation",'
            . '"reservationNumber":"SX-4410932","pickupTime":"2026-09-14T10:00:00+02:00",'
            . '"dropoffTime":"2026-09-18T09:00:00+02:00",'
            . '"pickupLocation":{"@type":"Place","name":"Sixt Berlin Flughafen BER"},'
            . '"reservationFor":{"@type":"RentalCar","model":"Golf",'
            . '"brand":{"@type":"Brand","name":"Volkswagen"},'
            . '"rentalCompany":{"@type":"Organization","name":"Sixt"}}}';

        $events = $this->ingest($this->html($json));

        self::assertSame(ExtractionKind::Rental, $events[0]->kind);
        self::assertSame('Sixt · Volkswagen Golf', $events[0]->title);
        self::assertSame('2026-09-14 08:00', $events[0]->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-09-18 07:00', $events[0]->endsAt->format('Y-m-d H:i'));
    }

    public function testATrainBecomesATrainEvent(): void
    {
        $json = '{"@context":"https://schema.org","@type":"TrainReservation",'
            . '"reservationNumber":"XJ4K2P","reservationFor":{"@type":"TrainTrip",'
            . '"trainName":"ICE","trainNumber":"599","departurePlatform":"7",'
            . '"departureStation":{"@type":"TrainStation","name":"Frankfurt(Main)Hbf"},'
            . '"arrivalStation":{"@type":"TrainStation","name":"Berlin Hbf"},'
            . '"departureTime":"2026-09-14T07:02:00+02:00","arrivalTime":"2026-09-14T11:04:00+02:00"}}';

        $events = $this->ingest($this->html($json));

        self::assertSame(ExtractionKind::Train, $events[0]->kind);
        self::assertSame('ICE 599 Frankfurt(Main)Hbf → Berlin Hbf', $events[0]->title);
    }

    /**
     * ExtractionKind has no bus. Ticket is vague; Train would put a train glyph
     * on a coach booking, which reads as a bug rather than an approximation.
     */
    public function testACoachBookingIsFiledAsATicketRatherThanATrain(): void
    {
        $json = '{"@context":"https://schema.org","@type":"BusReservation",'
            . '"reservationNumber":"FB-7781234","reservationFor":{"@type":"BusTrip",'
            . '"busName":"FlixBus","busNumber":"N1",'
            . '"departureBusStop":{"@type":"BusStop","name":"München ZOB"},'
            . '"arrivalBusStop":{"@type":"BusStop","name":"Praha ÚAN Florenc"},'
            . '"departureTime":"2026-09-14T23:55:00+02:00","arrivalTime":"2026-09-15T05:40:00+02:00"}}';

        $events = $this->ingest($this->html($json));

        self::assertCount(1, $events);
        self::assertSame(ExtractionKind::Ticket, $events[0]->kind);
    }

    // ── The life of a booking ─────────────────────────────────────────────

    /** A resend is the same booking, not a second one. */
    public function testTheSameConfirmationTwiceIsOneEvent(): void
    {
        $html = $this->html($this->flight('XK7Q2R', 'LH', '400', 'FRA', 'JFK', '2026-09-14T13:25:00+02:00', '2026-09-14T16:10:00-04:00'));

        $this->ingest($html);
        $this->ingest($html);

        self::assertCount(1, $this->allEvents());
    }

    /**
     * These sources carry no SEQUENCE, so the reconciler orders by arrival —
     * which is the whole reason sequence is left at 0 rather than invented.
     */
    public function testALaterMailAboutTheSameBookingMovesTheEvent(): void
    {
        $this->ingest(
            $this->html($this->flight('XK7Q2R', 'LH', '400', 'FRA', 'JFK', '2026-09-14T13:25:00+02:00', '2026-09-14T16:10:00-04:00')),
            new DateTimeImmutable('2026-08-01 09:00:00'),
        );

        $events = $this->ingest(
            $this->html($this->flight('XK7Q2R', 'LH', '400', 'FRA', 'JFK', '2026-09-14T17:40:00+02:00', '2026-09-14T20:25:00-04:00')),
            new DateTimeImmutable('2026-08-02 09:00:00'),
        );

        self::assertCount(1, $this->allEvents());
        self::assertSame('2026-09-14 15:40', $events[0]->startsAt->format('Y-m-d H:i'));
    }

    /** Case and spacing are the sender's formatting, not part of the booking. */
    public function testAReservationNumberMatchesAcrossFormatting(): void
    {
        $this->ingest($this->html($this->flight('XK7Q2R', 'LH', '400', 'FRA', 'JFK', '2026-09-14T13:25:00+02:00', '2026-09-14T16:10:00-04:00')));
        $this->ingest($this->html($this->flight('xk7q 2r', 'LH', '400', 'FRA', 'JFK', '2026-09-14T13:25:00+02:00', '2026-09-14T16:10:00-04:00')));

        self::assertCount(1, $this->allEvents());
    }

    /** Cancelled is a state, not a delete — the answer to "wasn't there something?" */
    public function testACancellationMarksTheEventRatherThanRemovingIt(): void
    {
        $this->ingest(
            $this->html($this->lodging('4429318', 'Hotel Adlon Kempinski', '2026-10-02', '2026-10-05')),
            new DateTimeImmutable('2026-08-01 09:00:00'),
        );

        $events = $this->ingest(
            $this->html($this->lodging('4429318', 'Hotel Adlon Kempinski', '2026-10-02', '2026-10-05', 'ReservationCancelled')),
            new DateTimeImmutable('2026-08-02 09:00:00'),
        );

        self::assertCount(1, $this->allEvents());
        self::assertSame(EventStatus::Cancelled, $events[0]->status);

        foreach ($events[0]->occurrences as $occurrence) {
            self::assertTrue($occurrence->cancelled);
        }
    }

    /** The bare term is as legal as the URL, and senders use both. */
    public function testABareCancellationTermIsUnderstoodToo(): void
    {
        $events = $this->ingest(
            $this->html($this->lodging('9911', 'Hotel Sacher', '2026-10-02', '2026-10-04', 'ReservationCancelled', bareTerm: true)),
        );

        self::assertSame(EventStatus::Cancelled, $events[0]->status);
    }

    /** A held table is genuinely uncertain, and that is what the status is for. */
    public function testAPendingReservationIsTentative(): void
    {
        $events = $this->ingest(
            $this->html($this->lodging('9912', 'Hotel Sacher', '2026-10-02', '2026-10-04', 'ReservationPending')),
        );

        self::assertSame(EventStatus::Tentative, $events[0]->status);
    }

    /**
     * A six-character reservation number is unique only to the company that
     * issued it, which is why the sender's domain is in the key.
     */
    public function testTheSameNumberFromADifferentSenderIsADifferentBooking(): void
    {
        $html = $this->html($this->flight('AB1234', 'LH', '400', 'FRA', 'JFK', '2026-09-14T13:25:00+02:00', '2026-09-14T16:10:00-04:00'));

        $this->ingest($html, from: 'noreply@lufthansa.com');
        $this->ingest($html, from: 'noreply@united.com');

        self::assertCount(2, $this->allEvents());
    }

    /** Bulk-mail subdomains change without warning and must not orphan events. */
    public function testASubdomainChangeKeepsTheSameBooking(): void
    {
        $html = $this->html($this->flight('AB1234', 'LH', '400', 'FRA', 'JFK', '2026-09-14T13:25:00+02:00', '2026-09-14T16:10:00-04:00'));

        $this->ingest($html, from: 'noreply@lufthansa.com');
        $this->ingest($html, from: 'noreply@email.lufthansa.com');

        self::assertCount(1, $this->allEvents());
    }

    // ── Provenance ────────────────────────────────────────────────────────

    /**
     * The load-bearing one: the fragment is stored verbatim, which is what
     * makes improving a mapper a backfill rather than a resync.
     */
    public function testTheSourceFragmentIsStoredVerbatim(): void
    {
        $events = $this->ingest($this->html($this->flight('XK7Q2R', 'LH', '400', 'FRA', 'JFK', '2026-09-14T13:25:00+02:00', '2026-09-14T16:10:00-04:00')));

        $links = $this->em->getRepository(EventSourceLink::class)->findBy(['event' => $events[0]]);

        self::assertCount(1, $links);
        self::assertSame('structuredData', $links[0]->extractor);
        self::assertTrue($links[0]->applied);
        self::assertStringStartsWith('jsonld:', $links[0]->dedupKey);

        $payload = $links[0]->payload;

        self::assertSame('FlightReservation', $payload['type'] ?? null);
        self::assertSame('lufthansa.com', $payload['issuer'] ?? null);
        self::assertSame('FlightReservation', $payload['jsonld']['@type'] ?? null);
        self::assertSame('LH', $payload['jsonld']['reservationFor']['airline']['iataCode'] ?? null);
    }

    /**
     * The key formula, pinned.
     *
     * Nothing else in the application can recompute it — a suppression stores a
     * hash of it, and CalendarEvent::$dedupKeyVersion exists precisely because
     * changing it orphans every event already keyed the old way. So it is
     * written out here in full rather than read back from the code that
     * produced it.
     */
    public function testTheDedupKeyIsTheDocumentedFormula(): void
    {
        $events = $this->ingest($this->html($this->parcel('DHL', '00340434161094017981', '2026-09-16', '2026-09-16')));

        $links = $this->em->getRepository(EventSourceLink::class)->findBy(['event' => $events[0]]);
        $key   = $this->keyFor('ParcelDelivery', 'DHL/00340434161094017981');

        self::assertSame($key, $links[0]->dedupKey);
        self::assertSame(substr($key, strlen('jsonld:')) . '@plmail', $events[0]->uid);
    }

    /**
     * Dismissing a delivery has to survive re-extraction, or every backfill
     * puts back what the user just threw away.
     */
    public function testASuppressedBookingIsNotRecreated(): void
    {
        $suppression               = new EventSuppression();
        $suppression->usr          = $this->user;
        $suppression->dedupKeyHash = EventSuppressionRepository::hash(
            $this->keyFor('ParcelDelivery', 'DHL/00340434161094017981'),
        );
        $this->em->persist($suppression);
        $this->em->flush();

        $this->ingest($this->html($this->parcel('DHL', '00340434161094017981', '2026-09-16', '2026-09-16')));

        self::assertSame([], $this->allEvents());
    }

    /** The uid has to be ours and globally unique, like every other event's. */
    public function testTheUidIsStableAndOurs(): void
    {
        $html = $this->html($this->flight('XK7Q2R', 'LH', '400', 'FRA', 'JFK', '2026-09-14T13:25:00+02:00', '2026-09-14T16:10:00-04:00'));

        $first  = $this->ingest($html)[0]->uid;
        $second = $this->ingest($html)[0]->uid;

        self::assertSame($first, $second);
        self::assertStringEndsWith('@plmail', $first);
    }

    // ── Shapes ────────────────────────────────────────────────────────────

    /** @graph is the third legal way to write the same content. */
    public function testAGraphWrapperIsRead(): void
    {
        $json = '{"@context":"https://schema.org","@graph":['
            . $this->flight('XK7Q2R', 'LH', '400', 'FRA', 'JFK', '2026-09-14T13:25:00+02:00', '2026-09-14T16:10:00-04:00')
            . ']}';

        self::assertCount(1, $this->ingest($this->html($json)));
    }

    /** Two blocks in one body is the commonest way an itinerary arrives. */
    public function testSeveralScriptBlocksAreAllRead(): void
    {
        $html = '<p>Your trip</p>'
            . $this->block($this->flight('XK7Q2R', 'LH', '400', 'FRA', 'JFK', '2026-09-14T13:25:00+02:00', '2026-09-14T16:10:00-04:00'))
            . $this->block($this->lodging('4429318', 'Hotel Adlon Kempinski', '2026-09-14', '2026-09-17'));

        $events = $this->ingest($html);

        self::assertCount(2, $events);
        self::assertSame(
            [ExtractionKind::Flight, ExtractionKind::Lodging],
            array_map(static fn (CalendarEvent $e): ?ExtractionKind => $e->kind, $events),
        );
    }

    /** A charset parameter on the type attribute is legal and some senders send one. */
    public function testATypeAttributeWithACharsetIsStillJsonLd(): void
    {
        $html = '<script type="application/ld+json; charset=utf-8">'
            . $this->parcel('DHL', '00340434161094017981', '2026-09-16', '2026-09-16')
            . '</script>';

        self::assertCount(1, $this->ingest($html));
    }

    /** A type nobody has written a mapper for is not an error. */
    public function testAnUnmappedTypeIsIgnored(): void
    {
        $json = '{"@context":"https://schema.org","@type":"EmailMessage",'
            . '"potentialAction":{"@type":"ViewAction","url":"https://example.test/x"}}';

        self::assertSame([], $this->ingest($this->html($json)));
    }

    // ── Hostile and malformed input ───────────────────────────────────────

    /** Senders emit trailing commas and unsubstituted templating routinely. */
    public function testMalformedJsonCostsItsOwnBlockAndNothingElse(): void
    {
        $html = $this->block('{"@type":"FlightReservation",,,, oh dear')
            . $this->block('{{ order.json_ld }}')
            . $this->block($this->parcel('DHL', '00340434161094017981', '2026-09-16', '2026-09-16'));

        $events = $this->ingest($html);

        self::assertCount(1, $events);
        self::assertSame(ExtractionKind::Delivery, $events[0]->kind);
    }

    /** A body that is not really HTML is still a body. */
    public function testAMangledBodyIsSurvivable(): void
    {
        $html = '<<<>>> <div><p>unclosed <b>everything '
            . $this->block($this->parcel('DHL', '00340434161094017981', '2026-09-16', '2026-09-16'));

        self::assertCount(1, $this->ingest($html));
    }

    /**
     * A tracking URL is rendered in a description. A future "make links
     * clickable" change would turn a javascript: URL there into stored XSS, so
     * the scheme is checked when it is read rather than trusted downstream.
     */
    public function testAHostileTrackingUrlIsNotCarriedIntoTheDescription(): void
    {
        $json = '{"@context":"https://schema.org","@type":"ParcelDelivery",'
            . '"trackingNumber":"JD0002340991","carrier":{"@type":"Organization","name":"DPD"},'
            . '"trackingUrl":"javascript:alert(document.cookie)",'
            . '"expectedArrivalUntil":"2026-09-16"}';

        $events = $this->ingest($this->html($json));

        self::assertCount(1, $events);
        self::assertStringNotContainsString('javascript:', (string) ($events[0]->jscalendar['description'] ?? ''));
    }

    /** A sender does not get to decide how much work one message is. */
    public function testASenderCannotFloodTheCalendar(): void
    {
        $reservations = [];

        for ($i = 0; $i < 200; ++$i) {
            $reservations[] = $this->flight(sprintf('FLOOD%03d', $i), 'LH', (string) (100 + $i), 'FRA', 'JFK', '2026-09-14T13:25:00+02:00', '2026-09-14T16:10:00-04:00');
        }

        $events = $this->ingest($this->html('[' . implode(',', $reservations) . ']'));

        self::assertLessThanOrEqual(25, count($events));
        self::assertNotSame([], $events);
    }

    /** A sender-supplied relative expression would move every time it is replayed. */
    public function testARelativeDateIsNotADate(): void
    {
        $json = '{"@context":"https://schema.org","@type":"ParcelDelivery",'
            . '"trackingNumber":"JD0002340991","carrier":{"@type":"Organization","name":"DPD"},'
            . '"expectedArrivalUntil":"next thursday"}';

        self::assertSame([], $this->ingest($this->html($json)));
    }

    /** Nor does anybody get to own the top of "Happening Soon" until the year 9999. */
    public function testAnImplausibleYearIsTreatedAsNoDate(): void
    {
        $json = '{"@context":"https://schema.org","@type":"ParcelDelivery",'
            . '"trackingNumber":"JD0002340991","carrier":{"@type":"Organization","name":"DPD"},'
            . '"expectedArrivalUntil":"9998-01-01"}';

        self::assertSame([], $this->ingest($this->html($json)));
    }

    /** Nothing to recognise the booking by means every resend would be new. */
    public function testAParcelWithNoTrackingOrOrderNumberIsIgnored(): void
    {
        $json = '{"@context":"https://schema.org","@type":"ParcelDelivery",'
            . '"carrier":{"@type":"Organization","name":"DPD"},"expectedArrivalUntil":"2026-09-16"}';

        self::assertSame([], $this->ingest($this->html($json)));
    }

    /** Markup inside a comment is not markup — a quoted reply is full of it. */
    public function testJsonLdInsideAnHtmlCommentIsNotRead(): void
    {
        $html = '<!-- ' . $this->block($this->parcel('DHL', '00340434161094017981', '2026-09-16', '2026-09-16')) . ' -->';

        self::assertSame([], $this->ingest($html));
    }

    /** A body with no markup at all must never reach the parser. */
    public function testABodyWithNoMarkupIsNotParsed(): void
    {
        self::assertSame([], $this->ingest('<p>Just a normal email, thanks.</p>'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * A message carrying this body, run through the real extractor and the real
     * reconciler.
     *
     * @return list<CalendarEvent>
     */
    private function ingest(
        string             $bodyHtml,
        ?DateTimeImmutable $receivedAt = null,
        string             $from = 'noreply@lufthansa.com',
    ): array {
        $message = new Message();
        $message->account = $this->account;
        $message->subject = 'Your booking';
        $message->fromAddress = $from;
        $message->receivedAt = $receivedAt ?? new DateTimeImmutable();
        $message->hasAttachments = false;
        $message->bodyHtml = $bodyHtml;
        $message->messageId = sprintf('<%s@example.test>', uniqid('', true));
        $this->em->persist($message);
        $this->em->flush();

        $touched = $this->reconciler->reconcile($message, $this->runner->run($message));
        $this->em->flush();

        return $touched;
    }

    /** @return list<CalendarEvent> */
    private function allEvents(): array
    {
        return $this->em->getRepository(CalendarEvent::class)
            ->findBy(['calendar' => $this->calendar]);
    }

    /** sha256 over the issuer domain, the schema.org type and the identity. */
    private function keyFor(string $type, string $identity, string $issuer = 'lufthansa.com'): string
    {
        return 'jsonld:' . hash('sha256', implode("\0", [$issuer, $type, $identity]));
    }

    private function html(string $json): string
    {
        return '<html><body><p>Thanks for booking with us.</p>' . $this->block($json) . '</body></html>';
    }

    private function block(string $json): string
    {
        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * The shape an airline actually emits: the IATA code in airline.iataCode
     * and bare digits in flightNumber, times as local clock faces with offsets.
     */
    private function flight(
        string $reservationNumber,
        string $airline,
        string $number,
        string $from,
        string $to,
        string $departs,
        ?string $arrives = null,
    ): string {
        $arrival = null === $arrives ? '' : sprintf(',"arrivalTime":"%s"', $arrives);

        return sprintf(
            '{"@context":"https://schema.org","@type":"FlightReservation",'
            . '"reservationNumber":"%s","reservationStatus":"https://schema.org/ReservationConfirmed",'
            . '"underName":{"@type":"Person","name":"Paul Lützner"},'
            . '"reservationFor":{"@type":"Flight","flightNumber":"%s",'
            . '"airline":{"@type":"Airline","name":"Lufthansa","iataCode":"%s"},'
            . '"departureAirport":{"@type":"Airport","iataCode":"%s","name":"%s Airport"},'
            . '"arrivalAirport":{"@type":"Airport","iataCode":"%s","name":"%s Airport"},'
            . '"departureTime":"%s"%s}}',
            $reservationNumber,
            $number,
            $airline,
            $from,
            $from,
            $to,
            $to,
            $departs,
            $arrival,
        );
    }

    /** A courier's own notification: dates rather than times, and a tracking URL. */
    private function parcel(string $carrier, string $tracking, string $fromDate, string $untilDate): string
    {
        return sprintf(
            '{"@context":"https://schema.org","@type":"ParcelDelivery",'
            . '"trackingNumber":"%s","trackingUrl":"https://track.example.test/%s",'
            . '"carrier":{"@type":"Organization","name":"%s"},'
            . '"expectedArrivalFrom":"%s","expectedArrivalUntil":"%s",'
            . '"deliveryAddress":{"@type":"PostalAddress","streetAddress":"Musterweg 3",'
            . '"addressLocality":"Berlin","postalCode":"10115","addressCountry":"DE"}}',
            $tracking,
            $tracking,
            $carrier,
            $fromDate,
            $untilDate,
        );
    }

    /**
     * The retailer shape: the parcels nested in the Order rather than sent as
     * ParcelDelivery objects of their own.
     *
     * @param list<array{0:string,1:string,2:string}> $parcels carrier, tracking, arrival date
     */
    private function order(string $orderNumber, string $merchant, array $parcels): string
    {
        $deliveries = [];

        foreach ($parcels as [$carrier, $tracking, $date]) {
            $deliveries[] = sprintf(
                '{"@type":"ParcelDelivery","trackingNumber":"%s",'
                . '"carrier":{"@type":"Organization","name":"%s"},'
                . '"expectedArrivalFrom":"%s","expectedArrivalUntil":"%s"}',
                $tracking,
                $carrier,
                $date,
                $date,
            );
        }

        return sprintf(
            '{"@context":"https://schema.org","@type":"Order","orderNumber":"%s",'
            . '"orderStatus":"https://schema.org/OrderInTransit","orderDate":"2026-09-12",'
            . '"merchant":{"@type":"Organization","name":"%s"},"orderDelivery":[%s]}',
            $orderNumber,
            $merchant,
            implode(',', $deliveries),
        );
    }

    /** Dates where the vocabulary says times, which is what hotels send. */
    private function lodging(
        string $reservationNumber,
        string $hotel,
        string $checkin,
        string $checkout,
        string $status = 'ReservationConfirmed',
        bool   $bareTerm = false,
    ): string {
        return sprintf(
            '{"@context":"https://schema.org","@type":"LodgingReservation",'
            . '"reservationNumber":"%s","reservationStatus":"%s",'
            . '"checkinTime":"%s","checkoutTime":"%s",'
            . '"reservationFor":{"@type":"LodgingBusiness","name":"%s","telephone":"+49 30 22610",'
            . '"address":{"@type":"PostalAddress","streetAddress":"Unter den Linden 77",'
            . '"addressLocality":"Berlin","postalCode":"10117","addressCountry":"DE"}}}',
            $reservationNumber,
            true === $bareTerm ? $status : 'https://schema.org/' . $status,
            $checkin,
            $checkout,
            $hotel,
        );
    }

    private function seed(): void
    {
        $user = new User();
        $user->email = 'jsonld-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Json';
        $user->nameLast = 'Fixture';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);
        $this->user = $user;

        $account = new Account();
        $account->usr = $user;
        $account->email = 'Json Fixture';
        $account->username = 'jsonld-fixture@example.test';
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);
        $this->em->flush();

        $this->account = $account;

        $provisioner = self::getContainer()->get(CalendarProvisioner::class);
        $provisioner->defaultFor($user);
        $calendar = $provisioner->forAccount($account);
        $this->em->flush();

        self::assertNotNull($calendar);
        $this->calendar = $calendar;
    }
}
