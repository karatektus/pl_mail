<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use App\Domain\Interface\TimeGridEntryInterface;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEventOccurrence;
use DateTimeImmutable;

/**
 * The occurrences that draw one chip — one meeting, however many rows hold it.
 *
 * One meeting legitimately reaches plMail twice. An invitation arrives by mail
 * and is extracted onto the user's default calendar with the organiser's UID; the
 * provider auto-adds the same meeting to the user's primary calendar and the
 * mirror pulls it onto a Remote calendar, with the same UID and a remote id of
 * its own. Both rows are correct: they are two remote objects with their own
 * remoteId, etag and sync state, and collapsing them in the model would break
 * sync. The duplication is real in the data and false on the screen, so it is
 * answered here, at read time, and nowhere near the schema.
 *
 * **A lone occurrence is a cluster of one.** That is the reason this type
 * exists rather than a `list<CalendarEventOccurrence>|CalendarEventOccurrence`
 * union or a "duplicates" side-channel: every view keeps exactly one code path,
 * and the chip decides how to draw itself from $isMerged rather than from which
 * shape it was handed.
 *
 * $primary is the occurrence every single-valued question is answered from —
 * the time, the title, the event the editor opens on. Picking one is only
 * honest because a cluster of several exists only while its members agree on
 * everything a user would notice; the moment they do not, EventClusterer splits
 * them back into clusters of one and there is no winner to pick. See that class
 * for what "agree" means and why picking a winner would be a cover-up.
 *
 * $colors and $calendarNames are lists rather than a map keyed by calendar id,
 * because both are read positionally: the chip's conic-gradient needs the
 * colours in a fixed order to hand out equal slices, and the tooltip joins the
 * names in that same order so the two readings of the dot agree.
 *
 * extracted() answers the one question $primary cannot. $primary is whichever
 * member the query happened to return first, and that is enough for every
 * single-valued question a cluster of agreeing members can be asked — the time,
 * the title, the event an editor opens on. It is NOT enough for the things only
 * an extracted row carries: the plane-or-parcel icon, and the message behind
 * "why is this on my calendar?". A meeting extracted onto the account's calendar
 * and mirrored onto a Remote one is a cluster whose two members are equal about
 * everything a chip draws and utterly unequal about provenance, and which of the
 * two leads is decided by a sort this class does not own.
 */
final readonly class OccurrenceCluster implements TimeGridEntryInterface
{
    /**
     * @param list<CalendarEventOccurrence> $members
     * @param list<string>                  $colors        #rrggbb, one per member, in $members order
     * @param list<string>                  $calendarNames one per member, in $members order
     */
    private function __construct(
        public CalendarEventOccurrence $primary,
        public array                   $members,
        public array                   $colors,
        public array                   $calendarNames,
        public bool                    $isMerged,
    ) {
    }

    /**
     * A named constructor rather than a public one, so the derived fields can
     * never be handed in out of step with the members they describe.
     *
     * @param non-empty-list<CalendarEventOccurrence> $members
     */
    public static function of(array $members): self
    {
        $colors = [];
        $names  = [];

        foreach ($members as $member) {
            // `??` without `?->`: the coalesce already answers for a null
            // calendar, and the nullsafe in front of it is the redundancy
            // PHPStan refuses. The column is NOT NULL; the property is
            // nullable only because Doctrine constructs before it fills in.
            $colors[] = $member->calendar->color ?? Calendar::DEFAULT_COLOR;
            $names[]  = $member->calendar->name ?? '';
        }

        return new self($members[0], $members, $colors, $names, 1 < count($members));
    }

    /**
     * Where a time-grid draws this, answered from the primary — which is the
     * whole cluster's answer, because members that disagree about when they are
     * have already been split apart by EventClusterer. See
     * TimeGridEntryInterface for why the grid asks through an interface rather
     * than reaching for the occurrence itself.
     */
    public function gridStartsAt(): ?DateTimeImmutable
    {
        return $this->primary->startsAt;
    }

    public function gridEndsAt(): ?DateTimeImmutable
    {
        return $this->primary->endsAt;
    }

    public function occupiesWholeDay(): bool
    {
        return true === $this->primary->event?->isAllDay;
    }

    /**
     * The member an extractor put here, or null when nobody did.
     *
     * "Extracted" is asked of CalendarEvent rather than re-derived from the
     * kind, so the answer is the same one isExtracted() gives everywhere else —
     * the dismiss button, the "found in your email" affordances — and cannot
     * drift from it the day a kindless extraction source is added.
     *
     * First match wins rather than a merge of all of them. A cluster with two
     * extracted members is a meeting whose invitation was read onto two
     * calendars, and both members then carry the same kind and the same thread
     * behind them; there is nothing to choose between and choosing the first is
     * stable across renders because the caller's ordering is.
     */
    public function extracted(): ?CalendarEventOccurrence
    {
        foreach ($this->members as $member) {
            if (true === $member->event?->isExtracted()) {
                return $member;
            }
        }

        return null;
    }
}
