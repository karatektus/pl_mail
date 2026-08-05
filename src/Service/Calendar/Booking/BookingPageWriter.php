<?php

declare(strict_types=1);

namespace App\Service\Calendar\Booking;

use App\Entity\Calendar\BookingPage;
use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarRepository;
use App\Service\Calendar\Sharing\PublicLinkToken;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place a booking page is created, edited or re-tokened, and the one
 * place its numbers are made to make sense.
 *
 * Every field on this entity is a number a form can post, and most of the
 * combinations are nonsense: a slot longer than the working day, an end before
 * a start, a buffer measured in weeks, a horizon of zero days. Clamping them
 * here rather than validating them means the page always renders — a public URL
 * that 500s because the owner typed 0 in a box is worse than one that quietly
 * offers no times — and it means the read side can be written against values
 * that are already sane. The read side clamps again anyway, because this is not
 * the only writer.
 *
 * **The destination calendar is re-resolved against the user**, like
 * ShareLinkWriter's covered calendars and for the identical reason: the form
 * posts an id, and an id naming somebody else's calendar is the difference
 * between a booking page and a way to write into a stranger's diary. A page
 * with no resolvable destination is refused outright rather than saved
 * destinationless, because unlike a share link covering nothing, a booking page
 * with nowhere to write is a page that accepts appointments and loses them.
 *
 * **Editing does not re-token**, matching ShareLinkWriter — a published URL
 * must survive the owner changing their hours. regenerate() is the other case.
 *
 * Does not flush; it joins the caller's unit of work.
 */
final readonly class BookingPageWriter
{
    /** A slot no shorter than this, matching BookingPage::MIN_SLOT_MINUTES. */
    private const int MIN_SLOT = BookingPage::MIN_SLOT_MINUTES;

    /**
     * The most quiet time a page may reserve around a meeting.
     *
     * Four hours. Beyond that the buffer stops being "leave me room to travel"
     * and becomes a way of expressing availability, which is what the weekday
     * and hour fields are for — and a buffer measured in days would widen the
     * occurrence query by the same amount on every public request.
     */
    private const int MAX_BUFFER_MINUTES = 240;

    /**
     * The longest a page may make somebody wait before the first bookable slot.
     *
     * Thirty days. A longer notice period than that is a horizon, and setting
     * both would produce a page with no slots at all — which reads as broken
     * rather than as configured.
     */
    private const int MAX_NOTICE_MINUTES = 43200;

    public function __construct(
        private CalendarRepository     $calendars,
        private PublicLinkToken        $tokens,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * A new page, and the only moment its token is readable.
     *
     * Returns the token rather than putting it on the entity, for the reason
     * ShareLinkWriter::create() gives.
     *
     * @param array<mixed> $weekdays        as posted, so entirely untrusted
     * @param array<mixed> $busyCalendarIds as posted, so entirely untrusted
     */
    public function create(User $user, BookingPage $page, array $weekdays, array $busyCalendarIds, int $calendarId): string
    {
        $page->usr = $user;

        $token = $this->tokens->mint();

        $page->tokenDigest = $this->tokens->digest($token);

        $this->apply($page, $user, $weekdays, $busyCalendarIds, $calendarId);

        $this->em->persist($page);

        return $token;
    }

    /**
     * Everything about an existing page except its token.
     *
     * @param array<mixed> $weekdays        as posted, so entirely untrusted
     * @param array<mixed> $busyCalendarIds as posted, so entirely untrusted
     */
    public function update(BookingPage $page, User $user, array $weekdays, array $busyCalendarIds, int $calendarId): void
    {
        $this->apply($page, $user, $weekdays, $busyCalendarIds, $calendarId);
    }

    /**
     * A new token, and the published URL stops working at once.
     *
     * Re-enables as a side effect, matching ShareLinkWriter::regenerate(): a
     * disabled page whose token is regenerated is a page the owner is bringing
     * back, and minting a credential for something that answers nothing is not
     * a state worth being able to reach.
     */
    public function regenerate(BookingPage $page): string
    {
        $token = $this->tokens->mint();

        $page->tokenDigest = $this->tokens->digest($token);
        $page->isEnabled   = true;

        return $token;
    }

    /** Remove a page. Its bookings go with it — see CalendarBooking's cascade. */
    public function delete(BookingPage $page): void
    {
        $this->em->remove($page);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param array<mixed> $weekdays
     * @param array<mixed> $busyCalendarIds
     */
    private function apply(BookingPage $page, User $user, array $weekdays, array $busyCalendarIds, int $calendarId): void
    {
        $calendar = $this->calendars->findOneForUser($user, $calendarId);

        // Refused rather than saved with no destination, unlike a share link
        // covering no calendars. A link that shows nothing is visibly useless;
        // a booking page with nowhere to write accepts appointments and drops
        // them, and the person who finds out is the booker.
        if (null === $calendar || true === $calendar->isReadOnly) {
            throw new \InvalidArgumentException('A booking page needs a calendar that accepts writes.');
        }

        $page->calendar = $calendar;
        $page->name     = '' === trim($page->name) ? 'Appointment' : mb_substr(trim($page->name), 0, 120);
        $page->weekdays = $this->isoWeekdays($weekdays);
        $page->timeZone = $this->safeZoneName($page->timeZone);

        $page->checkAgainst($this->ownedCalendars($user, $busyCalendarIds));

        $page->startMinute = $this->clamp($page->startMinute, 0, BookingPage::MINUTES_IN_DAY - self::MIN_SLOT);
        $page->endMinute   = $this->clamp($page->endMinute, $page->startMinute + self::MIN_SLOT, BookingPage::MINUTES_IN_DAY);

        // Never longer than the bookable day, or the page offers nothing and
        // says nothing about why. Clamped to the day rather than refused,
        // because the value the user meant is obvious and losing the rest of
        // their edit over it is not a trade worth making.
        $page->slotMinutes   = $this->clamp($page->slotMinutes, self::MIN_SLOT, $page->openMinutes());
        $page->bufferMinutes = $this->clamp($page->bufferMinutes, 0, self::MAX_BUFFER_MINUTES);
        $page->noticeMinutes = $this->clamp($page->noticeMinutes, 0, self::MAX_NOTICE_MINUTES);
        $page->horizonDays   = $this->clamp($page->horizonDays, 1, BookingPage::MAX_HORIZON_DAYS);
    }

    /**
     * The posted weekdays, as ISO numbers, deduplicated and in order.
     *
     * Ordered so two pages open on the same days store the same list and the
     * settings summary reads the same way — the reasoning ShareDetail::fromList()
     * gives about declaration order, applied to a set of integers.
     *
     * @param array<mixed> $weekdays
     *
     * @return list<int>
     */
    private function isoWeekdays(array $weekdays): array
    {
        $chosen = [];

        foreach ($weekdays as $weekday) {
            if (false === is_scalar($weekday)) {
                continue;
            }

            $day = (int) $weekday;

            if ($day >= 1 && $day <= 7) {
                $chosen[$day] = true;
            }
        }

        $ordered = array_keys($chosen);
        sort($ordered);

        return $ordered;
    }

    /**
     * @param array<mixed> $calendarIds
     *
     * @return list<Calendar>
     */
    private function ownedCalendars(User $user, array $calendarIds): array
    {
        $calendars = [];

        foreach ($calendarIds as $calendarId) {
            if (false === is_scalar($calendarId)) {
                continue;
            }

            $calendar = $this->calendars->findOneForUser($user, (int) $calendarId);

            if (null !== $calendar) {
                $calendars[(int) $calendar->id] = $calendar;
            }
        }

        return array_values($calendars);
    }

    /** A zone PHP knows, or UTC. The form offers a list; this is what a crafted post meets. */
    private function safeZoneName(string $candidate): string
    {
        try {
            return new DateTimeZone($candidate)->getName();
        } catch (\Exception) {
            return 'UTC';
        }
    }

    private function clamp(int $value, int $low, int $high): int
    {
        return max($low, min($high, $value));
    }
}
