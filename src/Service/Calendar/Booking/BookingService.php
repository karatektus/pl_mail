<?php

declare(strict_types=1);

namespace App\Service\Calendar\Booking;

use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Exception\BookingRefusedException;
use App\Domain\Exception\BookingSlotTakenException;
use App\Entity\Calendar\BookingPage;
use App\Entity\Calendar\CalendarBooking;
use App\Entity\Calendar\CalendarEvent;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Taking a slot: the event it writes, the row that holds it, and the race it
 * loses safely.
 *
 * ── How double-booking is stopped ─────────────────────────────────────────
 *
 * Not by checking. Two strangers pressing Book on the same half hour at the
 * same instant both read the slot list before either writes to it, so every
 * check-then-insert answers "free" to both of them — narrowing the window
 * between the read and the write makes the bug rarer and never makes it go
 * away, and "rarer" is the worst possible property for a bug that quietly
 * puts two people in one appointment.
 *
 * What stops it is uniq_calendar_booking_page_start on CalendarBooking. The
 * second INSERT is refused by Postgres, the refusal aborts the transaction the
 * event was written in, and the loser is told the slot has gone. The database
 * is the only participant that sees both requests, so it is the only one that
 * can decide between them.
 *
 * **The event and the booking are written in ONE flush**, and that is the other
 * half. Claiming the slot first and writing the meeting afterwards would leave
 * a claimed hour with no meeting in it the moment anything between the two
 * failed; writing the meeting first and claiming afterwards would leave the
 * loser's meeting on the owner's calendar with nothing pointing at it. One unit
 * of work means the constraint's refusal takes the event with it — the whole
 * booking happens or none of it does.
 *
 * **The EntityManager is closed once that has happened**, which Doctrine does
 * to any manager whose flush threw, and the caller must not touch the database
 * afterwards. That is why this throws rather than returning a verdict: an
 * exception is the one signal a controller cannot accidentally continue past.
 * BookingController answers it with a redirect, so the re-offered slot list is
 * built by a fresh request with a fresh manager.
 *
 * ── What a booked event is ────────────────────────────────────────────────
 *
 * An ordinary CalendarEvent on the calendar the page names, written through
 * CalendarEventWriter like every other event in this application, and carrying
 * EventSource::Booking. Going through the writer is what makes the rest work
 * for free: the canonical JSCalendar object and the projected columns cannot
 * disagree, the occurrence row exists so the calendar views can see it, and
 * markLocallyCreated() plus the dispatch below is exactly the path an event
 * created in the editor takes — so a booking on a synced calendar reaches
 * Google, Graph or CalDAV without a second push mechanism existing.
 *
 * **The booker is not a participant on the event.** They are named in the
 * title and the description instead, and this is deliberate: pushing an
 * attendee list to a provider is how the provider decides to send the
 * invitation again to everybody on it, which would mail a stranger a meeting
 * request they did not ask for on top of the confirmation they did. The same
 * reasoning EventCopyResolver::rowFor() gives for not copying participants
 * onto a new copy.
 */
final readonly class BookingService
{
    /**
     * The crudest possible check that an address could be one.
     *
     * Deliberately not a validator. A booking refused because a deliverable
     * address failed a regular expression is a person who does not book, and
     * the confirmation either arrives or it does not — there is no state here
     * that a wrong address corrupts. This exists only to keep the obviously
     * empty and the obviously not-an-address out of a column that is rendered
     * back to the owner.
     */
    private const string ADDRESS_SHAPE = '/^[^@\s]+@[^@\s]+\.[^@\s]+$/';

    public function __construct(
        private BookingAvailabilityReader $availability,
        private CalendarEventWriter       $writer,
        private BookingConfirmationMailer $confirmations,
        private MessageBusInterface       $bus,
        private EntityManagerInterface    $em,
        private LoggerInterface           $logger,
    ) {
    }

    /**
     * Take a slot, or explain why not.
     *
     * @throws BookingRefusedException  the request was wrong; re-render the form
     * @throws BookingSlotTakenException somebody else won; re-offer the list
     */
    public function book(
        BookingPage       $page,
        DateTimeImmutable $now,
        string            $slotKey,
        string            $name,
        string            $email,
        ?string           $note,
        string            $bookerTimeZone,
    ): CalendarBooking {
        $calendar = $page->calendar;

        // Checked here as well as in the settings form, for the reason
        // IcsController gives about read-only calendars: the first check is
        // what a person meets, the second is what a crafted request meets — and
        // a booking written onto a mirror that accepts no writes back is a
        // meeting the owner's real calendar never hears about.
        if (true === $calendar->isReadOnly || false === $page->isEnabled) {
            throw new BookingRefusedException('This booking page is not available.');
        }

        $bookerName  = trim($name);
        $bookerEmail = trim($email);

        if ('' === $bookerName) {
            throw new BookingRefusedException('Enter your name so the organiser knows who is coming.');
        }

        if (1 !== preg_match(self::ADDRESS_SHAPE, $bookerEmail)) {
            throw new BookingRefusedException('Enter an email address the confirmation can be sent to.');
        }

        $slot = $this->availability->findFreeSlot($page, $now, $slotKey);

        // Not a taken-slot failure. A posted instant that matches nothing the
        // page generates is a stale form or a crafted request, and answering it
        // with "somebody got there first" would be a lie that sends the person
        // round the loop again — see BookingException.
        if (null === $slot) {
            throw new BookingRefusedException('That time is no longer being offered. Please choose another.');
        }

        $event = new CalendarEvent();

        // Before write(), because write() projects the columns and persists the
        // row: setting it afterwards would leave the moment between the two
        // with an event claiming a person typed it, and any listener on the
        // persist would see the wrong answer.
        $event->source = EventSource::Booking;

        $this->writer->write(
            event:       $event,
            calendar:    $calendar,
            user:        $page->usr,
            title:       $this->titleFor($page, $bookerName),
            startsAt:    $slot->startsAt,
            endsAt:      $slot->endsAt,
            timeZone:    $page->timeZone,
            isAllDay:    false,
            location:    null,
            description: $this->descriptionFor($bookerName, $bookerEmail, $note),
            status:      EventStatus::Confirmed,
        );

        // The same call the editor makes for a brand-new event, and the reason
        // a booking on a synced calendar reaches the provider at all. A no-op
        // on a calendar that mirrors nothing, which is why there is no branch.
        $this->writer->markLocallyCreated($event);

        $booking                 = new CalendarBooking();
        $booking->page           = $page;
        $booking->usr            = $page->usr;
        $booking->event          = $event;
        $booking->startsAt       = $slot->startsAt;
        $booking->endsAt         = $slot->endsAt;
        $booking->bookerName     = mb_substr($bookerName, 0, CalendarBooking::MAX_NAME_LENGTH);
        $booking->bookerEmail    = mb_substr($bookerEmail, 0, 255);
        $booking->note           = null === $note || '' === trim($note)
            ? null
            : mb_substr(trim($note), 0, CalendarBooking::MAX_NOTE_LENGTH);
        $booking->bookerTimeZone = $this->safeZoneName($bookerTimeZone, $page->timeZone);

        $this->em->persist($booking);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // The guarantee, arriving. Everything written in this unit of work
            // — the event, its occurrence, the booking — went back with it, so
            // there is nothing to undo here and nothing half-written left
            // behind. The manager is closed; the caller must redirect rather
            // than read.
            throw new BookingSlotTakenException('That time has just been taken. Please choose another.', 0, $e);
        }

        // After the flush, so the worker reads committed rows — the same
        // ordering CalendarController uses for its own saves.
        if (true === $calendar->isSynced()) {
            $this->bus->dispatch(new SyncCalendarMessage((int) $calendar->id));
        }

        $this->confirmations->send($booking);

        return $booking;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * What the meeting is called in the owner's calendar.
     *
     * The booker's name is in it because the owner's week is a list of titles
     * and "30 minute intro call" four times over says nothing. The page's name
     * leads, so the entries still sort and read as a group.
     */
    private function titleFor(BookingPage $page, string $bookerName): string
    {
        return mb_substr(sprintf('%s — %s', $page->name, $bookerName), 0, 250);
    }

    /**
     * The booker's details, as the event's description.
     *
     * Here rather than in participants for the reason in the class docblock. It
     * is also the only copy that travels: a synced calendar carries the
     * description to the provider, so the owner reading the meeting on their
     * phone still knows who booked it and how to reach them.
     */
    private function descriptionFor(string $name, string $email, ?string $note): string
    {
        $lines = [
            sprintf('Booked by %s <%s>', $name, $email),
        ];

        if (null !== $note && '' !== trim($note)) {
            $lines[] = '';
            $lines[] = trim($note);
        }

        return implode("\n", $lines);
    }

    /**
     * The booker's zone, or the page's when they named one PHP does not know.
     *
     * The value arrives from a form field the browser filled in, so it is
     * whatever the visitor's machine claims — a name from a newer tz database,
     * an empty string, or something typed. Falling back to the page's zone
     * keeps the column meaningful; throwing would refuse a booking over a
     * display preference.
     */
    private function safeZoneName(string $candidate, string $fallback): string
    {
        try {
            return new DateTimeZone($candidate)->getName();
        } catch (\Exception) {
            $this->logger->info('Booking: unknown booker time zone, falling back to the page zone', [
                'candidate' => mb_substr($candidate, 0, 64),
            ]);

            return $fallback;
        }
    }
}
