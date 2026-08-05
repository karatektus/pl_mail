<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Booking;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Exception\BookingRefusedException;
use App\Entity\Calendar\BookingPage;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarBooking;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Service\Calendar\Booking\BookingAvailabilityReader;
use App\Service\Calendar\Booking\BookingService;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Taking a slot writes a meeting that says where it came from, and the same
 * slot cannot be taken twice.
 *
 * ── The claim that matters ────────────────────────────────────────────────
 *
 * Double-booking is stopped by uniq_calendar_booking_page_start and by nothing
 * else. Every check performed in PHP happens before the write, so two requests
 * arriving together both see the slot free however carefully the reader is
 * written — narrowing that window makes the bug rarer, which is the worst
 * property a bug of this kind can have. What the database refuses cannot be
 * raced.
 *
 * **How that is tested, and what the test cannot do.** A genuine race needs two
 * requests committing on two connections, and the suite's fixtures live in a
 * transaction that is never committed — a second connection would not see the
 * page or the calendar the booking hangs off, so the insert would fail on a
 * foreign key rather than on the constraint under test and prove nothing. So
 * the guarantee is asserted where it lives: a second CalendarBooking naming the
 * same page and the same instant is refused by Postgres with a
 * UniqueConstraintViolationException, which is precisely the exception
 * BookingService::book() catches and turns into BookingSlotTakenException. Drop
 * the unique index and this test fails; the check-then-insert version of the
 * service passes it unchanged, which is the point — the test is about the
 * schema, because the schema is the mechanism.
 *
 * The read-side half is asserted separately: a slot already booked is not
 * offered, and booking it is refused. That is what a person meets; the
 * constraint is what a race meets.
 *
 * ── The other claim ───────────────────────────────────────────────────────
 *
 * A booked event is a distinct thing and says so. EventSource::Booking is on
 * the row, it lands on the calendar the page named, and it is NOT an extracted
 * event — a kind would put it behind every "found in your email" affordance and
 * file it into Happening Soon beside parcel deliveries.
 *
 * Against a real container and a real database, in a transaction that is never
 * committed. Mail is the test transport's, so the confirmation is composed for
 * real and goes nowhere.
 */
final class BookingServiceTest extends KernelTestCase
{
    /** A Monday, so the fixture page's weekday rules are stable. */
    private const string MONDAY = '2026-06-01';

    private EntityManagerInterface $em;
    private Connection $connection;
    private BookingService $bookings;
    private BookingAvailabilityReader $availability;
    private CalendarEventWriter $writer;
    private CalendarEventRepository $events;
    private User $user;
    private Calendar $calendar;
    private BookingPage $page;

    protected function setUp(): void
    {
        self::bootKernel();

        $container          = self::getContainer();
        $this->em           = $container->get(EntityManagerInterface::class);
        $this->connection   = $container->get(Connection::class);
        $this->bookings     = $container->get(BookingService::class);
        $this->availability = $container->get(BookingAvailabilityReader::class);
        $this->events       = $container->get(CalendarEventRepository::class);
        $this->writer       = $container->get(CalendarEventWriter::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
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

    /**
     * The guarantee, asserted against the database rather than against the
     * service — see the class docblock for why that is the honest place for it.
     */
    public function testTheDatabaseRefusesASecondBookingOfTheSameSlot(): void
    {
        $booking = $this->book('10:00');

        // A SECOND event, not the first one's. calendar_booking also carries a
        // unique index on event_id, and reusing the event would make this test
        // pass with uniq_calendar_booking_page_start dropped — which is exactly
        // the mutation it exists to fail against.
        $second              = new CalendarBooking();
        $second->page        = $this->page;
        $second->usr         = $this->user;
        $second->event       = $this->writer->write(
            event:    new CalendarEvent(),
            calendar: $this->calendar,
            user:     $this->user,
            title:    'The loser of the race',
            startsAt: $booking->startsAt,
            endsAt:   $booking->endsAt,
            timeZone: 'Europe/Berlin',
        );
        $second->startsAt    = $booking->startsAt;
        $second->endsAt      = $booking->endsAt;
        $second->bookerName  = 'The other one';
        $second->bookerEmail = 'other@example.test';

        $this->em->persist($second);

        // The one assertion in this file that must come last: the flush aborts
        // the transaction, so nothing may be queried afterwards.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->em->flush();
    }

    /** What a person meets, rather than what a race meets. */
    public function testASlotSomebodyTookIsNoLongerOffered(): void
    {
        $slot = $this->firstSlotAt('10:00');

        $this->book('10:00');

        self::assertNull(
            $this->availability->findFreeSlot($this->page, $this->now(), $slot),
            'a booked slot is still being offered',
        );
    }

    public function testBookingASlotThatIsAlreadyTakenIsRefused(): void
    {
        $slot = $this->firstSlotAt('10:00');

        $this->book('10:00');

        $this->expectException(BookingRefusedException::class);

        $this->bookings->book($this->page, $this->now(), $slot, 'Second', 'second@example.test', null, 'UTC');
    }

    /**
     * The slot is re-derived from the page's own availability, never trusted
     * from the form. Without that, the endpoint is "POST any instant you like
     * into somebody's calendar".
     */
    public function testAnInstantThePageNeverOfferedIsRefusedRatherThanWritten(): void
    {
        $midnight = new DateTimeImmutable(self::MONDAY . ' 03:00:00', new DateTimeZone('Europe/Berlin'))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $this->expectException(BookingRefusedException::class);

        $this->bookings->book($this->page, $this->now(), $midnight, 'Chancer', 'chancer@example.test', null, 'UTC');
    }

    public function testABookedEventSaysItWasBookedRatherThanTyped(): void
    {
        $booking = $this->book('10:00');

        $event = $booking->event;

        self::assertSame(EventSource::Booking, $event->source);
        self::assertSame($this->calendar->id, $event->calendar?->id, 'the booking did not land on the page\'s calendar');
        self::assertSame(EventStatus::Confirmed, $event->status);

        // Not an extraction. A kind would make isExtracted() answer true and put
        // the meeting behind every "found in your email" affordance.
        self::assertNull($event->kind);
        self::assertFalse($event->isExtracted());

        // The badge the calendar draws exists for this source and for no other.
        self::assertNotNull($event->source->icon());
        self::assertNull(EventSource::Manual->icon());
    }

    /**
     * The booker's name and address are on the meeting, so the owner reading it
     * on their phone knows who booked and how to reach them — but NOT as
     * participants, which is how a provider decides to mail a stranger a meeting
     * request they never asked for.
     */
    public function testTheBookerIsNamedOnTheEventButIsNotAParticipant(): void
    {
        $booking = $this->book('10:00', name: 'Ada Lovelace', email: 'ada@example.test', note: 'About the engine');

        $event = $booking->event;

        self::assertStringContainsString('Ada Lovelace', (string) $event->title);
        self::assertStringContainsString('ada@example.test', (string) ($event->jscalendar['description'] ?? ''));
        self::assertStringContainsString('About the engine', (string) ($event->jscalendar['description'] ?? ''));
        self::assertArrayNotHasKey('participants', $event->jscalendar);
    }

    /** The event has to be visible in the calendar views, which read occurrences. */
    public function testTheBookedEventIsMaterialisedSoTheCalendarCanSeeIt(): void
    {
        $booking = $this->book('10:00');

        $event = $this->events->find((int) $booking->event->id);

        self::assertNotNull($event);
        self::assertCount(1, $event->occurrences);
    }

    public function testABookingWithNoNameIsRefused(): void
    {
        $slot = $this->firstSlotAt('10:00');

        $this->expectException(BookingRefusedException::class);

        $this->bookings->book($this->page, $this->now(), $slot, '   ', 'someone@example.test', null, 'UTC');
    }

    public function testABookingWithSomethingThatIsNotAnAddressIsRefused(): void
    {
        $slot = $this->firstSlotAt('10:00');

        $this->expectException(BookingRefusedException::class);

        $this->bookings->book($this->page, $this->now(), $slot, 'Someone', 'not-an-address', null, 'UTC');
    }

    /**
     * A read-only calendar is checked twice — the form will not offer one, and
     * this is what a crafted request or a calendar that became a mirror later
     * meets. A booking written onto a mirror that accepts no writes back is a
     * meeting the owner's real calendar never hears about.
     */
    public function testAPageWhoseCalendarStoppedAcceptingWritesRefusesTheBooking(): void
    {
        $slot = $this->firstSlotAt('10:00');

        $this->calendar->isReadOnly = true;
        $this->em->flush();

        $this->expectException(BookingRefusedException::class);

        $this->bookings->book($this->page, $this->now(), $slot, 'Someone', 'someone@example.test', null, 'UTC');
    }

    /**
     * The booker's zone is recorded so the confirmation can be written in their
     * clock, and a zone PHP does not know falls back to the page's rather than
     * refusing a booking over a display preference.
     */
    public function testAnUnknownBookerZoneFallsBackToThePagesRatherThanRefusing(): void
    {
        $booking = $this->book('10:00', zone: 'Mars/Olympus_Mons');

        self::assertSame('Europe/Berlin', $booking->bookerTimeZone);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::MONDAY . ' 06:00:00', new DateTimeZone('Europe/Berlin'));
    }

    /** The slot key for a wall clock on the fixture Monday, as the form would post it. */
    private function firstSlotAt(string $clock): string
    {
        return new DateTimeImmutable(self::MONDAY . ' ' . $clock . ':00', new DateTimeZone('Europe/Berlin'))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function book(
        string  $clock,
        string  $name = 'Somebody',
        string  $email = 'somebody@example.test',
        ?string $note = null,
        string  $zone = 'Europe/Berlin',
    ): CalendarBooking {
        return $this->bookings->book(
            $this->page,
            $this->now(),
            $this->firstSlotAt($clock),
            $name,
            $email,
            $note,
            $zone,
        );
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'booking-service-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Booking';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->name     = 'Bookings';
        $calendar->role     = CalendarRole::Custom;
        $calendar->timeZone = 'Europe/Berlin';
        $this->em->persist($calendar);

        $page                = new BookingPage();
        $page->usr           = $user;
        $page->calendar      = $calendar;
        $page->name          = 'Intro call';
        $page->tokenDigest   = hash('sha256', uniqid('', true));
        $page->timeZone      = 'Europe/Berlin';
        $page->weekdays      = [1, 2, 3, 4, 5];
        $page->startMinute   = 540;
        $page->endMinute     = 1020;
        $page->slotMinutes   = 30;
        $page->noticeMinutes = 120;
        $page->horizonDays   = 5;
        $page->checkAgainst([$calendar]);
        $this->em->persist($page);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;
        $this->page     = $page;
    }
}
