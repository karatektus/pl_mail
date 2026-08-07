<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sharing;

use App\Domain\DTO\Calendar\SharedCalendarView;
use App\Domain\DTO\Calendar\SharedOccurrence;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\ShareDetail;
use App\Domain\Enum\Calendar\ShareWindow;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Entity\Calendar\CalendarShareLink;
use App\Repository\Calendar\CalendarEventOccurrenceRepository;
use App\Repository\Calendar\CalendarShareLinkRepository;
use App\Service\Calendar\CalendarTimeResolver;
use App\Service\Calendar\EventClusterer;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Turning a token in a URL into the only thing a stranger is allowed to see.
 *
 * Two jobs that must not be separated, because separating them is how a leak
 * gets written: resolving the link, and building the redacted view. Nothing
 * else in the application may hand a public template a CalendarEvent, and the
 * way that rule is enforced is that this class is the only route from a token
 * to anything renderable, and what it returns carries no events at all — see
 * SharedOccurrence, which is the redaction rather than a record of it.
 *
 * ── Resolution ────────────────────────────────────────────────────────────
 *
 * A token is hashed and looked up; a revoked link, an unknown token and a
 * malformed one all answer null, and the controller makes one 404 out of all
 * three. Distinguishing them would confirm which tokens had once been real,
 * which is the same rule DevicePairingService::redeem() states for its own
 * three failure modes.
 *
 * ── The window ────────────────────────────────────────────────────────────
 *
 * Resolved in the OWNER's zone, always, and never in the reader's. A rolling
 * fortnight means a fortnight of the owner's days; a fixed range names two
 * dates on the owner's calendar. Resolving it in the visitor's zone would make
 * the same link cover different days depending on where it was opened, and the
 * last day of a "conference week" link would appear or vanish over the
 * international date line.
 *
 * The rolling count is clamped rather than trusted. It is bounded on the way in
 * by the form too, but this is the read side and it walks days — a row edited
 * by anything other than that form must not be able to turn one public GET into
 * a decade of date arithmetic.
 *
 * ── Privacy is the ceiling, the ticks are the floor ───────────────────────
 *
 * EventPrivacy::isShareable() drops secret events entirely and
 * mayRevealDetail() silences private ones down to a busy block, whatever the
 * link's checkboxes say. Both are asked here and not in the template, because a
 * template that forgets is a template that leaks and there is no test that
 * notices a missing `if`.
 *
 * ── One meeting, one block ────────────────────────────────────────────────
 *
 * A link may name several calendars, and a meeting that reached plMail twice —
 * extracted from its invitation onto the account's calendar, mirrored from the
 * provider onto a Remote one — sits on two of them under the organiser's UID.
 * Left alone that is two blocks at the same hour on somebody else's screen, and
 * two VEVENTs in the .ics a subscriber's client files as two meetings. Neither
 * is a leak, but both are a lie about how busy the owner is, which is the one
 * thing this page exists to state.
 *
 * So the occurrences go through EventClusterer before they are redacted, and
 * only the primary of each cluster survives. Redacting first and merging after
 * would be the wrong order twice over: the merge asks about titles and statuses
 * that redaction has already thrown away, and a busy/free link would end up
 * comparing blocks that were made deliberately indistinguishable — every
 * meeting in the same hour would collapse into one.
 *
 * The cluster's other members are dropped rather than named. A shared page says
 * nothing about the owner's calendars — not their names, not their colours, not
 * how many there are — and "this one is on two of them" is exactly that fact.
 *
 * Cancelled is dropped for a different reason and it is worth separating: a
 * called-off meeting is not a claim on the owner's time, so leaving it in would
 * make a shared calendar say "busy" at an hour the owner is free. That is also
 * half of "a cancelled or moved owner event frees its slot again" — the reader
 * asks the occurrence table on every request rather than caching a window, so a
 * cancellation is reflected by the next page load and a moved instance is at
 * its new time because the materialiser already rewrote the row.
 */
final readonly class ShareLinkReader
{
    /**
     * How many entries a single shared window may render.
     *
     * A year-long link over a busy calendar is tens of thousands of
     * occurrences, and this page has no pagination — it is one scroll, sent to
     * somebody who was asked to look at it. The cap is on the query rather than
     * on the loop so the memory is bounded too, and the page says when it has
     * been reached rather than silently ending early.
     */
    private const int MAX_ENTRIES = 2000;

    public function __construct(
        private CalendarShareLinkRepository       $links,
        private CalendarEventOccurrenceRepository $occurrences,
        private CalendarTimeResolver              $time,
        private PublicLinkToken                   $tokens,
        private EventClusterer                    $clusterer,
    ) {
    }

    /**
     * The live link a token names, or null.
     *
     * Null covers unknown, revoked, and a token whose owner has gone — three
     * facts the caller must not be able to tell apart, and which it cannot,
     * because they arrive as one value.
     */
    public function resolve(string $token): ?CalendarShareLink
    {
        $link = $this->links->findOneByDigest($this->tokens->digest($token));

        if (null === $link || false === $link->isLive()) {
            return null;
        }

        return $link;
    }

    /**
     * What the page renders, with everything the link does not reveal already
     * gone.
     *
     * $now is passed rather than read, so a test can put the rolling window
     * where it wants it and so the window and the "is this in the past" checks
     * cannot disagree about what time it is within one request.
     */
    public function read(CalendarShareLink $link, DateTimeImmutable $now): SharedCalendarView
    {
        $zone = $this->zoneOf($link);

        [$from, $to] = $this->window($link, $now, $zone);

        $calendarIds = [];

        foreach ($link->calendars as $calendar) {
            $calendarIds[] = (int) $calendar->id;
        }

        $entries = [];

        if ([] !== $calendarIds) {
            $entries = $this->occurrences->findInRange(
                $link->usr,
                $calendarIds,
                // Padded a day either side for the reason CalendarRangeReader
                // pads its own: all-day events are stored floating at local
                // midnight while timed ones are UTC, so a calendar read well
                // east or west of UTC has entries belonging to a day the raw
                // window does not quite cover. The day walk below throws away
                // whatever the padding dragged in.
                $from->modify('-1 day')->setTimezone(new DateTimeZone('UTC')),
                $to->modify('+1 day')->setTimezone(new DateTimeZone('UTC')),
            );
        }

        return new SharedCalendarView(
            $from,
            $to,
            $zone->getName(),
            $this->groupByLocalDay($this->redactAll($link, $entries), $zone, $from, $to),
            [] === $link->revealed(),
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The zone the window is resolved in and the page is drawn on: the owner's.
     *
     * Through CalendarTimeResolver rather than off a calendar, so a link
     * covering three calendars in three zones has one answer, and so this and
     * the owner's own calendar view agree about what "today" is.
     */
    private function zoneOf(CalendarShareLink $link): DateTimeZone
    {
        return $this->time->zoneFor($link->usr);
    }

    /**
     * The window, as two instants in the owner's zone.
     *
     * Exhaustive over ShareWindow with no default, which is the point of the
     * enum: a third shape cannot be added without this deciding what it means.
     *
     * A fixed range with a missing or backwards pair falls back to the rolling
     * behaviour rather than answering an empty window. An empty window renders
     * as a page saying "nothing here", which is indistinguishable from a diary
     * that happens to be clear — so a half-saved link would look like it was
     * working.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function window(CalendarShareLink $link, DateTimeImmutable $now, DateTimeZone $zone): array
    {
        $today = $now->setTimezone($zone)->modify('midnight');
        $days  = max(1, min(CalendarShareLink::MAX_ROLLING_DAYS, $link->rollingDays));

        return match ($link->windowMode) {
            ShareWindow::Rolling => [$today, $today->modify(sprintf('+%d days', $days))],
            ShareWindow::Fixed   => $this->fixedWindow($link, $today, $days, $zone),
        };
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function fixedWindow(
        CalendarShareLink $link,
        DateTimeImmutable $today,
        int               $rollingDays,
        DateTimeZone      $zone,
    ): array {
        $startsOn = $link->startsOn;
        $endsOn   = $link->endsOn;

        if (null === $startsOn || null === $endsOn || $endsOn < $startsOn) {
            return [$today, $today->modify(sprintf('+%d days', $rollingDays))];
        }

        // Read as wall-clock dates in the owner's zone. The column is a DATE and
        // Doctrine hands it back at midnight in whatever zone PHP is set to, so
        // taking it as an instant would shift the whole window by the offset —
        // a Berlin owner's "the 3rd to the 5th" would begin at 01:00 on the 3rd
        // and end an hour into the 5th.
        $from = new DateTimeImmutable($startsOn->format('Y-m-d') . ' 00:00:00', $zone);

        // Exclusive end from an INCLUSIVE date: the form says "up to and
        // including the 5th", so the window has to run to midnight on the 6th
        // or the last day is silently empty.
        $to = new DateTimeImmutable($endsOn->format('Y-m-d') . ' 00:00:00', $zone)->modify('+1 day');

        return [$from, $to];
    }

    /**
     * @param list<CalendarEventOccurrence> $occurrences
     *
     * @return list<SharedOccurrence>
     */
    private function redactAll(CalendarShareLink $link, array $occurrences): array
    {
        $revealed = $link->revealed();
        $entries  = [];

        // Collapsed before the cap, not after: MAX_ENTRIES is a bound on what
        // the page renders, and two rows of one meeting must not spend two of
        // it. See the class docblock for why the merge happens before the
        // redaction rather than after.
        foreach ($this->clusterer->cluster($occurrences) as $cluster) {
            if (count($entries) >= self::MAX_ENTRIES) {
                break;
            }

            $entry = $this->redact($link, $revealed, $cluster->primary);

            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * One occurrence, reduced to what this link may say about it — or null when
     * it may say nothing at all.
     *
     * @param list<ShareDetail> $revealed
     */
    private function redact(
        CalendarShareLink       $link,
        array                   $revealed,
        CalendarEventOccurrence $occurrence,
    ): ?SharedOccurrence {
        $event = $occurrence->event;

        if (null === $event || null === $occurrence->startsAt || null === $occurrence->endsAt) {
            return null;
        }

        // A called-off meeting is not a claim on the owner's time. Both halves
        // are asked: the series can be cancelled, or one instance struck out,
        // and they are separate states.
        if (EventStatus::Cancelled === $event->status || true === $occurrence->cancelled) {
            return null;
        }

        if (false === $event->privacy->isShareable()) {
            return null;
        }

        // The ceiling. A private event is a busy block whatever the link ticks
        // — see EventPrivacy::mayRevealDetail().
        $mayDetail = $event->privacy->mayRevealDetail();

        $shows = static fn (ShareDetail $detail): bool => true === $mayDetail
            && true === in_array($detail, $revealed, true);

        return new SharedOccurrence(
            startsAt:     $occurrence->startsAt,
            endsAt:       $occurrence->endsAt,
            isAllDay:     $event->isAllDay,
            uid:          $this->syntheticUid($link, $occurrence),
            title:        true === $shows(ShareDetail::Title) ? $this->text($event->title) : null,
            location:     true === $shows(ShareDetail::Location) ? $this->text($event->location) : null,
            description:  true === $shows(ShareDetail::Description)
                ? $this->text($this->stringField($event->jscalendar, 'description'))
                : null,
            participants: true === $shows(ShareDetail::Participants) ? $this->participantsOf($event->jscalendar) : [],
        );
    }

    /**
     * The UID a shared .ics carries for one entry.
     *
     * Derived from the link's own digest and the occurrence's id rather than
     * being the event's UID, because the event's UID is the meeting's identity
     * everywhere — the same string an invitation carried into somebody's inbox.
     * Publishing it on a busy/free link would let a recipient who had received
     * that invitation match it against the anonymous block and learn what the
     * link deliberately withheld.
     *
     * Stable across requests, so re-importing the .ics updates the entry the
     * previous import created instead of duplicating it, and different per link,
     * so two links to the same calendar cannot be correlated by their contents.
     */
    private function syntheticUid(CalendarShareLink $link, CalendarEventOccurrence $occurrence): string
    {
        return sprintf('%s@plmail.share', hash('sha256', $link->tokenDigest . ':' . (int) $occurrence->id));
    }

    /**
     * The display names of an event's participants, or their addresses when
     * they have no name.
     *
     * Names first because that is what a person reading the page recognises,
     * and the address as the fallback rather than as an addition: publishing
     * both would put a list of harvestable addresses on a page whose whole
     * audience is "whoever was sent the URL".
     *
     * @param array<string,mixed> $jscalendar
     *
     * @return list<string>
     */
    private function participantsOf(array $jscalendar): array
    {
        $participants = $jscalendar['participants'] ?? null;

        if (false === is_array($participants)) {
            return [];
        }

        $names = [];

        foreach ($participants as $participant) {
            if (false === is_array($participant)) {
                continue;
            }

            $name = $this->text($this->stringField($participant, 'name'))
                ?? $this->text($this->stringField($participant, 'email'));

            if (null !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<mixed> $source
     */
    private function stringField(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return true === is_string($value) ? $value : null;
    }

    /**
     * Trimmed, and empty becomes absent.
     *
     * An empty string renders as a labelled row with no label, which reads as a
     * bug rather than as "no title" — and on a busy/free link it would be
     * indistinguishable from a redacted one, which is worse: it teaches the
     * reader that blank means hidden.
     */
    private function text(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * Keyed Y-m-d in the owner's zone, every day in the window present even
     * when empty.
     *
     * The same shape and the same reasoning as CalendarRangeReader's day walk:
     * a page needs its blank days, and building them in Twig means repeating
     * the date arithmetic in every template that renders one.
     *
     * An entry lands on the day it starts. A meeting running past midnight is
     * drawn once, on the day it began, rather than on both — a shared page is a
     * list rather than a grid, and an entry appearing twice reads as two
     * meetings.
     *
     * @param list<SharedOccurrence> $entries
     *
     * @return array<string, list<SharedOccurrence>>
     */
    private function groupByLocalDay(
        array             $entries,
        DateTimeZone      $zone,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $days   = [];
        $cursor = $from->setTimezone($zone)->modify('midnight');

        while ($cursor < $to) {
            $days[$cursor->format('Y-m-d')] = [];
            $cursor = $cursor->modify('+1 day');
        }

        foreach ($entries as $entry) {
            // An all-day entry is floating — a wall date at midnight carrying no
            // zone — so it is read as it is stored. Converting it into the
            // link's zone moves it, which files it on the day before for any
            // reader west of UTC. Same rule as CalendarRangeReader.
            $key = true === $entry->isAllDay
                ? $entry->startsAt->format('Y-m-d')
                : $entry->startsAt->setTimezone($zone)->format('Y-m-d');

            if (true === array_key_exists($key, $days)) {
                $days[$key][] = $entry;
            }
        }

        return $days;
    }
}
