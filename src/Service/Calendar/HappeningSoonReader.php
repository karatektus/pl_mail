<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\DTO\Calendar\HappeningSoonRow;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventOccurrenceRepository;
use App\Repository\Calendar\CalendarRepository;
use App\Repository\Calendar\EventSourceLinkRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * What plMail has read out of mail and expects to happen shortly — the flights,
 * deliveries, reservations, meetings and tickets, in one list.
 *
 * This is the read side of "Happening Soon" and the only caller of
 * CalendarEventOccurrenceRepository::findUpcomingExtracted(), which was written
 * when extraction shipped and had nothing to surface it. The filter is
 * CalendarEvent::$kind being set at all: a kind is exactly what distinguishes an
 * event an extractor produced from one a person typed, so the feature needs no
 * table, no flag and no second list to stay in step with the calendar.
 *
 * **Occurrences, not events.** A recurring extracted event — a standing weekly
 * call read out of its invitation — has one row in calendar_event and one row
 * per instance in calendar_event_occurrence, and "what is coming up" means the
 * next instance, not the series' original start two years ago. Reading the
 * event table would list that call once, dated wrongly, forever.
 *
 * **Proposals are deliberately absent.** An EventProposal is a date somebody
 * wrote in a sentence, offered and not yet accepted; it materialises no
 * occurrence precisely so that no view can leak it, and that is stated on the
 * entity as the reason the table exists at all. Listing proposals here beside
 * things that are actually happening would re-introduce exactly the leak that
 * design prevents — an invented flight time sitting in the list a user trusts to
 * be true — and it would strip the guess of the sentence it was read from, which
 * is the only thing that makes it judgeable in a second rather than on a coin
 * flip. A proposal is answered where its evidence is, on the message. See
 * EventProposal and Proposal/ProposalReader.
 *
 * Visible calendars only, like the topbar dot and every calendar view: a
 * calendar the user has switched off is switched off everywhere, or the setting
 * means nothing.
 */
final readonly class HappeningSoonReader
{
    /**
     * How far ahead "soon" reaches.
     *
     * A fortnight, and the number is the answer to "how long is something still
     * worth acting on?". Everything in this list is a booking: a flight can
     * still be rebooked, a delivery can still be redirected, a table can still
     * be cancelled, a ticket can still be given away — but only while the date
     * is close enough that a person would do something about it today. Beyond
     * two weeks the answer is always "not yet", and a list whose entries mean
     * "not yet" is one nobody reads.
     *
     * Two weeks rather than one, because "the flight next week" is the case this
     * feature exists for and a seven-day window drops it for six days out of
     * seven — on a Tuesday, next Wednesday is eight days away. Rather than a
     * month, because a month of bookings is a calendar, and the calendar is one
     * click away and better at being one.
     *
     * Well inside RecurrenceMaterialiser::HORIZON_FUTURE, so a recurring
     * extracted event always has its next instances materialised — a window
     * beyond that horizon would silently stop listing series while continuing to
     * list one-offs.
     */
    private const int WINDOW_DAYS = 14;

    /**
     * The most rows the panel will draw.
     *
     * A cap rather than paging: this is a glance surface, and a fortnight that
     * genuinely holds more than a dozen bookings is a fortnight to look at in
     * the calendar instead. Twelve is also what findUpcomingExtracted() already
     * defaulted to, so the number is stated once here and passed rather than
     * left to be agreed in two places.
     */
    private const int LIMIT = 12;

    public function __construct(
        private CalendarRepository                $calendars,
        private CalendarEventOccurrenceRepository $occurrences,
        private EventSourceLinkRepository         $sourceLinks,
    ) {
    }

    /**
     * The window's rows, soonest first.
     *
     * `$now` is injectable so a test can pin the window's far edge without
     * moving the system clock; nothing in the application passes it.
     *
     * @return list<HappeningSoonRow>
     */
    public function read(User $user, ?DateTimeImmutable $now = null, int $limit = self::LIMIT): array
    {
        $calendarIds = [];

        foreach ($this->calendars->findVisibleForUser($user) as $calendar) {
            $calendarIds[] = (int) $calendar->id;
        }

        if (0 === count($calendarIds)) {
            return [];
        }

        $utc   = new DateTimeZone('UTC');
        $now ??= new DateTimeImmutable('now', $utc);
        $from  = $now->setTimezone($utc);

        // The window is counted in whole days from this instant rather than
        // from local midnight. "Soon" is a duration, not a date range: pinning
        // it to midnight would make the same booking fall in or out of the list
        // depending on what time of day the page was loaded.
        $occurrences = $this->occurrences->findUpcomingExtracted(
            $user,
            $calendarIds,
            $from,
            $from->modify(sprintf('+%d days', self::WINDOW_DAYS)),
            $limit,
        );

        $events = [];

        foreach ($occurrences as $occurrence) {
            $event = $occurrence->event;

            if (null !== $event) {
                $events[] = $event;
            }
        }

        // One query for the whole page's provenance, after the window has
        // narrowed the set — asked per row it would be a query per row, and
        // asked before the window it would be a query over the whole mailbox's
        // extraction history to fill twelve lines.
        $sources = $this->sourceLinks->findLatestAppliedMessageByEvent($events);

        $rows = [];

        foreach ($occurrences as $occurrence) {
            $eventId = $occurrence->event?->id;
            $row     = HappeningSoonRow::of(
                $occurrence,
                null === $eventId ? null : ($sources[$eventId] ?? null),
            );

            if (null !== $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The next one, or nothing — what the topbar needs to decide whether to
     * offer the panel at all.
     *
     * The same code path as read() with a limit of one, rather than a query of
     * its own. The topbar renders on every authenticated page, so the temptation
     * is a cheaper "is there any?" count; two queries that answer the same
     * question in different words are two queries that eventually disagree about
     * what counts as soon, and the disagreement would show up as a button that
     * opens an empty panel.
     */
    public function next(User $user, ?DateTimeImmutable $now = null): ?HappeningSoonRow
    {
        return $this->read($user, $now, 1)[0] ?? null;
    }
}
