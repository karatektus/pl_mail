<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Booking;

use App\Domain\DTO\Calendar\BookableSlot;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\EventPrivacy;
use App\Domain\Enum\Calendar\EventStatus;
use App\Entity\Calendar\BookingPage;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarBooking;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Service\Calendar\Booking\BookingAvailabilityReader;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Free" means free against the owner's real diary, and it stays true when the
 * diary changes.
 *
 * The generator says which hours a page offers; this says which of them are
 * actually available, and the difference is the entire value of the feature. A
 * booking page that offered its configured hours and left the owner to sort out
 * the collisions would be worse than a piece of paper.
 *
 * Four claims, each of which fails silently if it is got wrong:
 *
 *   **An event blocks its hour**, whatever its privacy. A secret meeting has to
 *   block, or "too private to mention" becomes "bookable over" — and nothing
 *   about it may reach the page, which it does not, because the slot is simply
 *   absent.
 *
 *   **A cancelled event stops blocking.** This is half of "a cancelled or moved
 *   owner event frees its slot again". There is no cache to invalidate: the
 *   reader asks the occurrence table on every request, so the hour comes back on
 *   the next page load. The other half is the moved case below, which works for
 *   the same reason plus RecurrenceMaterialiser having already rewritten the row.
 *
 *   **A taken slot is not offered twice.** The read-side guard. It is not the
 *   thing that stops double-booking — the unique constraint is, see
 *   BookingServiceTest — but without it every page would show hours it was about
 *   to refuse.
 *
 *   **The buffer widens the busy interval, not the slot**, so the neighbours of
 *   a meeting go too.
 *
 * Against a real container and a real database, in a transaction that is never
 * committed. The occurrence rows this reads are written by
 * RecurrenceMaterialiser through CalendarEventWriter, and the behaviour worth
 * pinning is the one that emerges from the two together — a doubled writer would
 * assert the occurrence into existence rather than observe it.
 */
final class BookingAvailabilityReaderTest extends KernelTestCase
{
    /** A Monday. Every window below is anchored on it so the weekday rules are stable. */
    private const string MONDAY = '2026-06-01';

    private EntityManagerInterface $em;
    private Connection $connection;
    private BookingAvailabilityReader $reader;
    private CalendarEventWriter $writer;
    private User $user;
    private Calendar $calendar;
    private BookingPage $page;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->reader     = $container->get(BookingAvailabilityReader::class);
        $this->writer     = $container->get(CalendarEventWriter::class);

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

    public function testAnEmptyDiaryOffersTheWholeWorkingDay(): void
    {
        // 09:00–17:00 in half hours, Monday to Friday, over a five-day horizon
        // anchored on a Monday: five working days of sixteen slots.
        self::assertCount(80, $this->reader->freeSlots($this->page, $this->now()));
    }

    public function testAnEventRemovesTheSlotsItOverlaps(): void
    {
        $this->eventAt('10:00', '11:00');

        $slots = $this->reader->freeSlots($this->page, $this->now());

        self::assertNotContains('10:00', $this->localStartsOnMonday($slots));
        self::assertNotContains('10:30', $this->localStartsOnMonday($slots));
        self::assertContains('09:30', $this->localStartsOnMonday($slots), 'a slot ending as the meeting starts is still free');
        self::assertContains('11:00', $this->localStartsOnMonday($slots), 'a slot starting as the meeting ends is still free');
    }

    /**
     * The one case a naive implementation gets wrong on purpose: an event
     * nobody may read about still occupies the owner. Nothing about it reaches
     * the page — the hour is simply not offered, which looks exactly like an
     * hour outside the working day.
     */
    public function testASecretEventBlocksItsHourJustLikeAnyOther(): void
    {
        $event          = $this->eventAt('13:00', '14:00');
        $event->privacy = EventPrivacy::Secret;
        $this->em->flush();

        self::assertNotContains('13:00', $this->localStartsOnMonday($this->reader->freeSlots($this->page, $this->now())));
    }

    public function testACancelledEventFreesItsSlotAgain(): void
    {
        $event = $this->eventAt('10:00', '11:00');

        self::assertNotContains('10:00', $this->localStartsOnMonday($this->reader->freeSlots($this->page, $this->now())));

        $event->status = EventStatus::Cancelled;
        $this->em->flush();

        self::assertContains(
            '10:00',
            $this->localStartsOnMonday($this->reader->freeSlots($this->page, $this->now())),
            'calling the meeting off did not bring its hour back',
        );
    }

    /**
     * Moving an event moves the hole with it. There is nothing to invalidate:
     * the writer re-materialises the occurrence and the reader asks the table.
     */
    public function testMovingAnEventMovesTheHoleRatherThanLeavingItBehind(): void
    {
        $event = $this->eventAt('10:00', '11:00');

        $this->writer->write(
            event:    $event,
            calendar: $this->calendar,
            user:     $this->user,
            title:    'Moved',
            startsAt: $this->berlin('14:00'),
            endsAt:   $this->berlin('15:00'),
            timeZone: 'Europe/Berlin',
        );
        $this->em->flush();

        $starts = $this->localStartsOnMonday($this->reader->freeSlots($this->page, $this->now()));

        self::assertContains('10:00', $starts, 'the hour the meeting left is still blocked');
        self::assertNotContains('14:00', $starts, 'the hour the meeting moved to is still offered');
    }

    public function testTheBufferTakesTheNeighbouringSlotsToo(): void
    {
        $this->eventAt('10:00', '11:00');

        $this->page->bufferMinutes = 15;
        $this->em->flush();

        $starts = $this->localStartsOnMonday($this->reader->freeSlots($this->page, $this->now()));

        self::assertNotContains('09:30', $starts, 'the slot ending as the meeting starts survived a 15 minute buffer');
        self::assertNotContains('11:00', $starts, 'the slot starting as the meeting ends survived a 15 minute buffer');
        self::assertContains('09:00', $starts, 'the buffer reached further than it was asked to');
    }

    public function testASlotSomebodyHasAlreadyTakenIsNotOfferedAgain(): void
    {
        $this->bookingAt('12:00');

        self::assertNotContains('12:00', $this->localStartsOnMonday($this->reader->freeSlots($this->page, $this->now())));
    }

    /**
     * The notice period is what stops a stranger booking the next ten minutes.
     * Asserted from the other side too — without an upper bound on the assertion
     * a reader that returned nothing at all would also pass.
     */
    public function testNothingInsideTheNoticePeriodIsOffered(): void
    {
        // Two hours' notice from 09:15 on the Monday puts the first bookable
        // slot at 11:30 — the first half-hour boundary at or after 11:15.
        $now = new DateTimeImmutable(self::MONDAY . ' 09:15:00', new DateTimeZone('Europe/Berlin'));

        $starts = $this->localStartsOnMonday($this->reader->freeSlots($this->page, $now));

        self::assertNotContains('09:30', $starts);
        self::assertNotContains('11:00', $starts);
        self::assertContains('11:30', $starts, 'the notice period swallowed the whole day');
    }

    /**
     * A page whose destination is not in its own busy set must still not
     * double-book itself. The invariant is on the entity rather than in this
     * reader, and this is what proves the reader honours it.
     */
    public function testTheDestinationCalendarIsCheckedEvenWhenItWasNotTicked(): void
    {
        $this->page->checkAgainst([]);
        $this->em->flush();

        $this->eventAt('15:00', '16:00');

        self::assertNotContains('15:00', $this->localStartsOnMonday($this->reader->freeSlots($this->page, $this->now())));
    }

    /**
     * RFC 8984 §4.4.5. An all-day "working from home" banner marked free must
     * not erase a week of availability.
     */
    public function testAnEventMarkedFreeDoesNotBlockAnything(): void
    {
        $event      = $this->eventAt('10:00', '11:00');
        $jscalendar = $event->jscalendar;

        $jscalendar['freeBusyStatus'] = 'free';
        $event->jscalendar            = $jscalendar;

        $this->em->flush();

        self::assertContains('10:00', $this->localStartsOnMonday($this->reader->freeSlots($this->page, $this->now())));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** Just before the working day starts, so the whole Monday is inside the horizon. */
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::MONDAY . ' 06:00:00', new DateTimeZone('Europe/Berlin'));
    }

    private function berlin(string $clock): DateTimeImmutable
    {
        return new DateTimeImmutable(self::MONDAY . ' ' . $clock . ':00', new DateTimeZone('Europe/Berlin'));
    }

    /**
     * The local start times of the slots that fall on the fixture Monday.
     *
     * Scoped to one day so an assertion about "10:00" cannot be satisfied by
     * Tuesday's ten o'clock — which is exactly what would happen if the reader
     * blocked the right hour on the wrong date.
     *
     * @param list<BookableSlot> $slots
     *
     * @return list<string>
     */
    private function localStartsOnMonday(array $slots): array
    {
        $zone   = new DateTimeZone('Europe/Berlin');
        $starts = [];

        foreach ($slots as $slot) {
            $local = $slot->startsAt->setTimezone($zone);

            if (self::MONDAY === $local->format('Y-m-d')) {
                $starts[] = $local->format('H:i');
            }
        }

        return $starts;
    }

    private function eventAt(string $from, string $to): CalendarEvent
    {
        $event = $this->writer->write(
            event:    new CalendarEvent(),
            calendar: $this->calendar,
            user:     $this->user,
            title:    'In the diary',
            startsAt: $this->berlin($from),
            endsAt:   $this->berlin($to),
            timeZone: 'Europe/Berlin',
        );

        $this->em->flush();

        return $event;
    }

    private function bookingAt(string $clock): CalendarBooking
    {
        $utc = new DateTimeZone('UTC');

        $event = $this->writer->write(
            event:    new CalendarEvent(),
            calendar: $this->calendar,
            user:     $this->user,
            title:    'Already booked',
            startsAt: $this->berlin($clock),
            endsAt:   $this->berlin($clock)->modify('+30 minutes'),
            timeZone: 'Europe/Berlin',
        );

        $booking              = new CalendarBooking();
        $booking->page        = $this->page;
        $booking->usr         = $this->user;
        $booking->event       = $event;
        $booking->startsAt    = $this->berlin($clock)->setTimezone($utc);
        $booking->endsAt      = $this->berlin($clock)->modify('+30 minutes')->setTimezone($utc);
        $booking->bookerName  = 'Someone';
        $booking->bookerEmail = 'someone@example.test';

        $this->em->persist($booking);
        $this->em->flush();

        return $booking;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'booking-availability-' . uniqid('', true) . '@example.test';
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
        $page->bufferMinutes = 0;
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
