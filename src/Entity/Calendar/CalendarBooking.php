<?php

declare(strict_types=1);

namespace App\Entity\Calendar;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarBookingRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One slot somebody took, and who took it.
 *
 * **This row is the double-booking guard.** That is its first purpose and the
 * reason it is a table rather than three more fields on the event it created.
 * Two people pressing Book on the same slot at the same instant both see it
 * free, because both read before either wrote — no amount of checking first
 * closes that window, it only makes it narrower. What closes it is
 * uniq_calendar_booking_page_start below: the second INSERT is refused by
 * Postgres, the transaction it was part of takes the event with it, and the
 * loser is told the slot has gone. See BookingService, which is written around
 * that refusal rather than around a check.
 *
 * The columns the constraint spans are (booking_page_id, starts_at) and the
 * order is not arbitrary: every read of this table is scoped to one page — the
 * owner listing bookings, the availability reader excluding taken slots — so
 * the page has to lead for the index to serve them, and starts_at second is
 * what makes a range scan within a page possible.
 *
 * **A booking's own instant, not the event's.** starts_at is denormalised from
 * the event deliberately, and the denormalisation is the guarantee: if the
 * constraint spanned the event's column instead, moving the event would move
 * what "taken" means, and the owner dragging a booked meeting an hour later
 * would silently free the hour a stranger is already holding an invitation for.
 * The booking says when it was booked for; the event says where the meeting
 * currently is; they start equal and the owner is allowed to make them differ.
 *
 * **Deleting the event frees the slot.** The foreign key cascades, which is the
 * whole of "a cancelled owner event frees its slot again" for the booked half —
 * the row that was holding the slot goes with the meeting the owner called off,
 * and the next availability read offers it. It also means the booker's details
 * do not outlive the meeting they were given for, which is the right default
 * for somebody else's name and address.
 *
 * **No cancellation column, and the absence is deliberate.** A booker cannot
 * cancel: that would need a second credential, mailed to them, with its own
 * revocation and its own abuse surface, and none of it is required for a
 * booking to work. The owner cancels by deleting the meeting, which is a thing
 * the calendar already does, and the cascade above makes that the complete
 * operation. Adding a cancelled_at later means making the unique index partial
 * — `WHERE cancelled_at IS NULL` — and that is the sentence to remember, because
 * a plain unique index plus a cancelled flag is a slot nobody can ever rebook.
 */
#[ORM\Entity(repositoryClass: CalendarBookingRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'calendar_booking')]
// The owner's list of what has been booked, across pages.
#[ORM\Index(name: 'idx_calendar_booking_usr_starts', columns: ['usr_id', 'starts_at'])]
// The guarantee. Page leads because every read is scoped to one page and
// because the constraint is about one page's slots — two pages offering the
// same hour is a thing the owner may legitimately do, and it is their diary
// (through calendarsToCheck()) that stops it, not this. starts_at second so the
// index also serves "which slots in this window are taken", which is the query
// the availability reader makes on every public GET.
#[ORM\UniqueConstraint(name: 'uniq_calendar_booking_page_start', columns: ['booking_page_id', 'starts_at'])]
// One booking per event, and the direction that matters is this one: the event
// is created by the booking, so a second booking pointing at the same meeting
// would be a bug in this application rather than a race between two strangers.
#[ORM\UniqueConstraint(name: 'uniq_calendar_booking_event', columns: ['event_id'])]
class CalendarBooking
{
    use TimestampableTrait;

    /** What a booker's name may be, matching the column and the form's limit. */
    public const int MAX_NAME_LENGTH = 120;

    /** What a note may be. Long enough for a sentence, short enough not to be a payload. */
    public const int MAX_NOTE_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BookingPage::class)]
    #[ORM\JoinColumn(name: 'booking_page_id', nullable: false, onDelete: 'CASCADE')]
    public BookingPage $page;

    /**
     * The owner. Denormalised from the page for the reason CalendarEvent
     * denormalises it from the calendar: every authenticated read of this table
     * is scoped to a user, and joining the page in to find out whose it is
     * would be a join for nothing.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    /** The meeting this booking made. Deleting it frees the slot — see the class docblock. */
    #[ORM\ManyToOne(targetEntity: CalendarEvent::class)]
    #[ORM\JoinColumn(name: 'event_id', nullable: false, onDelete: 'CASCADE')]
    public CalendarEvent $event;

    /**
     * Non-nullable and without a default, unlike the older entities in this
     * directory whose equivalents are `?X = null`. A row that exists has one,
     * the column says so, and reading it before it has been assigned throws —
     * which is the right answer to a genuine mistake, and the same argument
     * TimestampableTrait makes about its two timestamps. The nullable spelling
     * costs a phpstan-doctrine "type mapping mismatch" per property, and that
     * baseline is a debt ledger rather than a licence.
     *
     * UTC, and the instant the unique constraint is about.
     */
    #[ORM\Column]
    public DateTimeImmutable $startsAt;

    /** UTC, exclusive, matching every other end in this schema. */
    #[ORM\Column]
    public DateTimeImmutable $endsAt;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    public string $bookerName = '';

    /**
     * Where the confirmation went.
     *
     * Not validated into an Address here: the booker typed it, the confirmation
     * either arrives or it does not, and a booking refused because a perfectly
     * deliverable address failed a regular expression is a worse outcome than a
     * confirmation that bounces. BookingService checks it is plausible and
     * nothing more.
     */
    #[ORM\Column(length: 255)]
    public string $bookerEmail = '';

    /** Whatever they wanted the owner to know. Plain text; every renderer escapes it. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $note = null;

    /**
     * The zone the booker was reading the page in when they booked.
     *
     * Kept because it is the only way to say the confirmation back to them in
     * the clock they chose, and because "they booked 09:00" means nothing
     * without it. Never used to compute anything — the instant above is the
     * fact, this is how it was displayed.
     */
    #[ORM\Column(length: 64, options: ['default' => 'UTC'])]
    public string $bookerTimeZone = 'UTC';
}
