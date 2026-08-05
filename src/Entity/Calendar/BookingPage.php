<?php

declare(strict_types=1);

namespace App\Entity\Calendar;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Calendar\BookingPageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The hours the owner is bookable, and the public page that offers them.
 *
 * One row is one *kind* of appointment — "30 minute intro call", "office hours"
 * — rather than one person's availability, because the parameters that differ
 * between those two are exactly the ones stored here: how long a slot is, how
 * much room to leave around it, which calendar it lands on, how far ahead
 * somebody may reach. A single availability record per user would make the
 * second kind of appointment a second account.
 *
 * The token is treated exactly as CalendarShareLink's is — SHA-256 at rest,
 * shown once — and for the same reason, which is written out in full there.
 * The difference worth naming is that a booking page's URL is meant to be
 * published rather than sent to one person, so losing it is more annoying;
 * that is an argument for copying it somewhere when it is minted, not an
 * argument for keeping a working credential in a table.
 *
 * ── Availability is uniform across the days it covers ─────────────────────
 *
 * $weekdays says which days, $startMinute and $endMinute say the hours, and
 * those hours are the same on every day chosen. Per-day hours — Mondays from
 * two, Fridays until noon — were rejected for this pass, and the rejection is
 * recorded rather than hidden: it is a strictly larger form, a strictly larger
 * validation surface and a strictly larger thing to get wrong across a DST
 * boundary, in exchange for a case that a second booking page already covers.
 * When it arrives it replaces these three columns with one jsonb map and
 * nothing outside SlotGenerator has to know.
 *
 * **Minutes from local midnight, not a TIME column.** The hours here are wall
 * clock in $timeZone and nothing else — 09:00 means nine in the morning on both
 * sides of a DST boundary, which is the whole reason they are not stored as
 * instants. Doctrine's time type would hand every reader a DateTimeImmutable
 * carrying a meaningless 1970 date in the server's zone, and every one of them
 * would have to remember to throw the date away. An integer cannot be
 * misread.
 *
 * ── Two sets of calendars, and neither is the other ───────────────────────
 *
 * $calendar is where a booking is WRITTEN. $busyCalendars are what a slot is
 * checked AGAINST. They are separate because the useful configuration is
 * asymmetric: bookings land on one calendar the owner keeps for them, and
 * "free" has to mean free against the work calendar, the personal one and the
 * mirrored one from Outlook. The destination is always consulted for busy-ness
 * whether or not it was ticked — see BookingAvailabilityReader, which enforces
 * that rather than trusting the form, because a page whose destination was not
 * in its own busy set would double-book itself on the second request.
 */
#[ORM\Entity(repositoryClass: BookingPageRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'booking_page')]
// The owner's own list.
#[ORM\Index(name: 'idx_booking_page_usr', columns: ['usr_id'])]
// The public lookup. Unique for the reason CalendarShareLink's is: every
// unauthenticated request resolves to exactly one page by this column alone,
// and it is the collision guard on the mint.
#[ORM\UniqueConstraint(name: 'uniq_booking_page_token_digest', columns: ['token_digest'])]
class BookingPage
{
    use TimestampableTrait;

    /** Hex SHA-256, matching CalendarShareLink::DIGEST_LENGTH. */
    public const int DIGEST_LENGTH = 64;

    /** Minutes in a day, and therefore the exclusive ceiling on $endMinute. */
    public const int MINUTES_IN_DAY = 1440;

    /**
     * How far ahead a booker may reach, at most.
     *
     * A year. The bound is not politeness: BookingSlotGenerator walks days, so
     * this is what stops a hand-edited form turning one public GET into a
     * decade of slot arithmetic — the same reason CalendarShareLink caps its
     * rolling window.
     */
    public const int MAX_HORIZON_DAYS = 366;

    /** The shortest slot worth offering, and the smallest step the grid uses. */
    public const int MIN_SLOT_MINUTES = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /**
     * Non-nullable and without a default, unlike the older entities in this
     * directory whose equivalents are `?X = null`. A row that exists has one,
     * the column says so, and reading it before it has been assigned throws —
     * which is the right answer to a genuine mistake, and the same argument
     * TimestampableTrait makes about its two timestamps. The nullable spelling
     * costs a phpstan-doctrine "type mapping mismatch" per property, and that
     * baseline is a debt ledger rather than a licence.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    /** Shown to the booker: "30 minute intro call". Also the event's title. */
    #[ORM\Column(length: 120)]
    public string $name = '';

    /** Optional prose on the public page. Plain text; the template escapes it. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $description = null;

    /** SHA-256 of the token, hex. The token itself is never stored — see the class docblock. */
    #[ORM\Column(length: self::DIGEST_LENGTH)]
    public string $tokenDigest = '';

    /**
     * Switched off rather than deleted, so the hours and the calendar survive a
     * page being taken down for a fortnight. A disabled page 404s exactly like
     * an unknown token — see BookingPageReader on why the two must not be
     * distinguishable from outside.
     */
    #[ORM\Column(options: ['default' => true])]
    public bool $isEnabled = true;

    /**
     * The zone the hours below are wall clock in — the owner's working day, not
     * the server's and not the booker's.
     *
     * Its own column rather than read off the destination calendar: a page's
     * hours are a fact about the owner's day, and moving a booking page to a
     * calendar that happens to be displayed in another zone must not silently
     * move the hours it offers.
     */
    #[ORM\Column(length: 64, options: ['default' => 'UTC'])]
    public string $timeZone = 'UTC';

    /**
     * ISO-8601 weekday numbers, 1 (Monday) to 7 (Sunday).
     *
     * ISO rather than PHP's `w`, which puts Sunday at 0 — the value that also
     * means "not set" in every loosely-typed comparison this could meet on the
     * way in from a form.
     *
     * @var list<int>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $weekdays = [];

    /** Minutes from local midnight where the bookable day begins. 540 is 09:00. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 540])]
    public int $startMinute = 540;

    /** Minutes from local midnight where it ends, exclusive. 1020 is 17:00. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1020])]
    public int $endMinute = 1020;

    /** How long one appointment is. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 30])]
    public int $slotMinutes = 30;

    /**
     * Quiet time either side of anything already in the diary, and either side
     * of a booking once it is made.
     *
     * Applied to the BUSY interval rather than to the slot, which is what makes
     * it symmetric without any special case: an event from 10:00 to 11:00 with
     * a fifteen minute buffer occupies 09:45 to 11:15 as far as the slot list
     * is concerned, so the slot before it and the slot after it both go.
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    public int $bufferMinutes = 0;

    /**
     * How soon from now a booking may be made — the "not in the next two hours"
     * rule.
     *
     * Separate from the buffer because it answers a different question. The
     * buffer is about the diary; this is about the owner's morning, and a page
     * that let a stranger book the next ten minutes would be a page nobody
     * could publish.
     */
    #[ORM\Column(options: ['default' => 120])]
    public int $noticeMinutes = 120;

    /** How far ahead the page offers, in days. Capped at MAX_HORIZON_DAYS. */
    #[ORM\Column(options: ['default' => 30])]
    public int $horizonDays = 30;

    /**
     * Where a booking is written.
     *
     * Not nullable and cascading: a page whose destination was deleted could
     * accept a booking it had nowhere to put, and answering that with a 500 on
     * a stranger's POST is worse than the page going away with the calendar. It
     * is the owner's own calendar in every case — the settings form offers only
     * calendars that accept writes, and BookingService refuses a read-only one
     * again, for the reason IcsController gives about checking twice.
     */
    #[ORM\ManyToOne(targetEntity: Calendar::class)]
    #[ORM\JoinColumn(name: 'calendar_id', nullable: false, onDelete: 'CASCADE')]
    public Calendar $calendar;

    /**
     * What "free" is checked against.
     *
     * @var Collection<int, Calendar>
     */
    #[ORM\ManyToMany(targetEntity: Calendar::class)]
    #[ORM\JoinTable(name: 'booking_page_calendar')]
    #[ORM\JoinColumn(name: 'booking_page_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'calendar_id', onDelete: 'CASCADE')]
    public private(set) Collection $busyCalendars;

    public function __construct()
    {
        $this->busyCalendars = new ArrayCollection();
    }

    /**
     * Whether this day of the week is offered at all.
     *
     * A method because it takes an argument and asks the stored list about it.
     * Cast on the way in, because a jsonb list round-trips as ints here but
     * arrives from a form as strings, and `in_array` with strict comparison
     * would answer false for every day on a row that had just been saved.
     */
    public function isOpenOn(int $isoWeekday): bool
    {
        return true === in_array($isoWeekday, array_map(intval(...), $this->weekdays), true);
    }

    /**
     * How many minutes of the day are bookable. Zero or less means a page that
     * offers nothing, which is a state the form refuses and the generator
     * survives.
     */
    public function openMinutes(): int
    {
        return $this->endMinute - $this->startMinute;
    }

    /**
     * The calendars a slot is checked against, with the destination always in
     * it.
     *
     * Here rather than in the reader because it is an invariant of the page
     * rather than a policy of one caller: a page that did not consider its own
     * destination busy would offer a slot it had already written a booking
     * into, every time, and there is no caller for which that is the right
     * answer.
     *
     * @return list<Calendar>
     */
    public function calendarsToCheck(): array
    {
        $calendars = [(int) $this->calendar->id => $this->calendar];

        foreach ($this->busyCalendars as $calendar) {
            $calendars[(int) $calendar->id] = $calendar;
        }

        return array_values($calendars);
    }

    /**
     * Check availability against exactly these calendars.
     *
     * Whole-set for the reason CalendarShareLink::cover() gives.
     *
     * @param list<Calendar> $calendars
     */
    public function checkAgainst(array $calendars): void
    {
        $this->busyCalendars->clear();

        foreach ($calendars as $calendar) {
            if (false === $this->busyCalendars->contains($calendar)) {
                $this->busyCalendars->add($calendar);
            }
        }
    }
}
