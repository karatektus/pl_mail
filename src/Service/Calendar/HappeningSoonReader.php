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
 * What is about to happen — the flights, deliveries, reservations, meetings and
 * tickets plMail read out of mail, and the appointments the owner typed, in one
 * list.
 *
 * This is the read side of "Happening Soon" and the only caller of
 * CalendarEventOccurrenceRepository::findUpcoming().
 *
 * **Extracted events are not the only ones listed**, and used to be. The filter
 * was CalendarEvent::$kind being set at all, which is exactly what distinguishes
 * an event an extractor produced from one a person typed — a clean rule, and the
 * wrong one for this panel. A user who creates "dentist, Thursday" and then
 * presses a button labelled "happening soon" is asking about Thursday, not about
 * provenance, and a list that answered with everything except the thing they
 * entered themselves read as broken. The kind survives as *decoration* — the row
 * still wears a plane or a box, and still links to the mail it came from — but
 * it no longer decides membership. See HappeningSoonRow, where both are nullable
 * for that reason.
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
 * **One meeting is one row.** A meeting can reach plMail twice by two honest
 * routes at once — extracted from its invitation onto the account's calendar,
 * mirrored from the provider onto a Remote calendar, both carrying the
 * organiser's UID — and both rows are correct. The calendar grid has answered
 * that on the screen since EventClusterer existed; this panel did not, and drew
 * the same meeting on two consecutive lines, which is the more damaging place
 * for it to happen: a grid draws two chips in one visibly shared hour, while a
 * list of twelve lines just claims there are two things. So the occurrences go
 * through the same clusterer the grid uses, and the rows are built from
 * clusters. Nothing here decides what "the same meeting" means; that lives in
 * one place and this is a caller of it.
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
     * worth acting on?". Most of this list is a booking: a flight can
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
     * genuinely holds more than a dozen things is a fortnight to look at in the
     * calendar instead. Twelve is also what findUpcoming() already defaulted to,
     * so the number is stated once here and passed rather than left to be agreed
     * in two places.
     */
    private const int LIMIT = 12;

    /**
     * The most occurrences one query will read, however many calendars are on.
     *
     * The window is capped in ROWS and the cap is applied before anything is
     * collapsed, so a limit of twelve occurrences is at most twelve meetings and
     * possibly fewer — a meeting on three calendars eats three of them. The fix
     * is to read more than the panel draws: a meeting can occupy at most one row
     * per visible calendar, because uniq_calendar_event_calendar_uid makes a UID
     * unique within one, so $limit × (visible calendars) occurrences cannot
     * collapse to fewer than $limit clusters.
     *
     * That product is unbounded by anything a user cannot change, hence this
     * ceiling. A user with forty visible calendars would otherwise turn a glance
     * panel into a five-hundred-row read on every topbar click.
     *
     * Being cut short by the ceiling cannot duplicate anything, which is the
     * property worth stating: a cluster whose second member fell outside the
     * fetched set is a cluster of one and draws ONE row. The cost of the cut is
     * a single-coloured dot where a multicoloured one belonged, and only on rows
     * far past the twelve the panel shows.
     */
    private const int MAX_SCAN = 120;

    public function __construct(
        private CalendarRepository                $calendars,
        private CalendarEventOccurrenceRepository $occurrences,
        private EventSourceLinkRepository         $sourceLinks,
        private EventClusterer                    $clusterer,
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
        // Read wide, drawn narrow — see MAX_SCAN. The clusters are capped after
        // the collapse, so the panel shows $limit MEETINGS rather than $limit
        // rows of which some are the same meeting twice.
        $clusters = $this->clusterer->cluster($this->occurrences->findUpcoming(
            $user,
            $calendarIds,
            $from,
            $from->modify(sprintf('+%d days', self::WINDOW_DAYS)),
            min(self::MAX_SCAN, $limit * count($calendarIds)),
        ));

        $clusters = array_slice($clusters, 0, $limit);

        $events = [];

        foreach ($clusters as $cluster) {
            // The member the row will speak for, resolved here and again inside
            // the row through the same method — the provenance looked up must be
            // the provenance of the copy whose icon is drawn.
            $event = HappeningSoonRow::representativeOf($cluster)->event;

            // Only the extracted ones. An event with no kind was typed here and
            // has no EventSourceLink by construction, so putting its id in the
            // IN below asks the provenance query a question whose answer is
            // known — and on a calendar of ordinary appointments that is the
            // whole list.
            if (null !== $event && null !== $event->kind) {
                $events[] = $event;
            }
        }

        // One query for the whole page's provenance, after the window has
        // narrowed the set — asked per row it would be a query per row, and
        // asked before the window it would be a query over the whole mailbox's
        // extraction history to fill twelve lines.
        $sources = [] === $events
            ? []
            : $this->sourceLinks->findLatestAppliedMessageByEvent($events);

        $rows = [];

        foreach ($clusters as $cluster) {
            $eventId = HappeningSoonRow::representativeOf($cluster)->event?->id;
            $row     = HappeningSoonRow::of(
                $cluster,
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
