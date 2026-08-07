<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use App\Domain\Enum\Calendar\ExtractionKind;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Entity\Mail\Message;
use DateTimeImmutable;

/**
 * One line of "Happening Soon": a thing that is about to happen, and — when
 * plMail read it out of mail rather than being told it — the mail it came from.
 *
 * A DTO rather than handing the occurrence straight to the template, because
 * three of the four things a row shows are not on it. The kind lives on the
 * event, the provenance lives on an EventSourceLink that has to be queried per
 * page rather than walked per row, and $startsAt is non-null here while every
 * column it comes from is nullable — Doctrine constructs entities before it
 * fills them, so the *entity* has to allow a null the *row* cannot contain.
 * Resolving all three once, in the reader, is what keeps the template free of
 * the `is not null` ladder that would otherwise decide whether a row renders.
 *
 * $kind and $source are the two fields that may legitimately be null, and they
 * are null together in the ordinary case: an event somebody typed has no
 * extraction kind and no message behind it. Both were once required, which is
 * what kept a hand-made appointment out of the panel entirely — see
 * CalendarEventOccurrenceRepository::findUpcoming(). They are still what makes
 * an extracted row *look* extracted; icon() and transKey() fall back rather than
 * letting the template grow a branch for it.
 *
 * $source can also be null on a row that does have a kind: an event keeps its
 * kind after the message behind it is expunged provider-side, and a row that
 * silently dropped its provenance would look identical to one that never had
 * any. What it must never be is the *superseded* claim — see
 * HappeningSoonReader, which picks the newest applied link, because "why is this
 * on my calendar?" is answered by the message the event currently reflects and
 * not by the first one that mentioned it.
 *
 * **A row is a CLUSTER, not an occurrence**, and that is what stops the panel
 * listing one meeting twice. A meeting that reached plMail by two honest routes
 * at once — extracted from its invitation onto the account's calendar, mirrored
 * from the provider onto a Remote one — is two rows in the database and one
 * thing that is about to happen; the calendar grid has drawn it as a single
 * merged chip since EventClusterer existed, and this panel drew it twice for as
 * long as it read occurrences. $cluster is carried through so the template can
 * wear the same multicolour affordance the chip does, which is what keeps
 * "collapsed" visible rather than merely quiet.
 *
 * $event, $kind and $source are read off the cluster's EXTRACTED member where
 * there is one, and off its primary otherwise. The primary is whichever row the
 * query returned first, and for the pair above that is a coin flip between a
 * mirrored copy that carries no kind and no message, and the extracted copy that
 * carries both. Taking the primary's answer would make the panel's icon and its
 * "why is this on my calendar?" link appear and disappear with the sort order.
 * Only provenance is chosen this way — the five things a user would notice are
 * identical across the members by construction, or EventClusterer would not have
 * merged them.
 */
final readonly class HappeningSoonRow
{
    /**
     * What a row with no extraction kind wears instead.
     *
     * A clock, and deliberately not a calendar. This icon is worn by the topbar
     * trigger as well as by the row, and that trigger sits immediately beside
     * the calendar switch — which in its resting position is
     * `fa-regular fa-calendar`. A calendar here put two near-identical calendar
     * glyphs side by side in the topbar, told apart only by the urgency dot on
     * one of them. It could not happen before: the panel listed extracted events
     * only, so the trigger always wore a plane or a box or a train.
     *
     * It reads correctly on its own terms too. The extraction kinds say WHAT is
     * coming up; where there is no kind, the only thing left to say is THAT
     * something is.
     */
    private const string DEFAULT_ICON = 'fa-regular fa-clock';

    /** And what it is called: an appointment, because that is all it claims. */
    private const string DEFAULT_TRANS_KEY = 'calendar.kind.event';

    private function __construct(
        public CalendarEventOccurrence $occurrence,
        public OccurrenceCluster       $cluster,
        public CalendarEvent           $event,
        public ?ExtractionKind         $kind,
        public DateTimeImmutable       $startsAt,
        public ?Message                $source,
    ) {
    }

    /**
     * A named constructor rather than a public one, so a row can never be built
     * out of step with the cluster it describes — the occurrence, the event and
     * the kind are read off it here rather than passed in beside it.
     *
     * $source is the message behind the member this row speaks for, resolved by
     * the reader against the same member this picks: see representativeOf(),
     * which both go through so the icon and the provenance link cannot end up
     * describing two different rows of one meeting.
     *
     * Answers null for a cluster with nothing to draw: no event, or no start.
     * Both are impossible for a row the repository returned and both are
     * nullable on the entity, which is the whole reason this exists — it is what
     * lets $event and $startsAt above be non-nullable.
     */
    public static function of(OccurrenceCluster $cluster, ?Message $source): ?self
    {
        $occurrence = self::representativeOf($cluster);
        $event      = $occurrence->event;

        if (null === $event || null === $occurrence->startsAt) {
            return null;
        }

        return new self($occurrence, $cluster, $event, $event->kind, $occurrence->startsAt, $source);
    }

    /**
     * Which member of a cluster this panel speaks for.
     *
     * Public because the reader asks it too, before this row exists: it needs
     * the representative's EVENT to look provenance up by, and one question
     * answered in two places is two answers waiting to disagree — the shape of
     * the disagreement being an icon off one copy and a message link off
     * another, for one meeting.
     */
    public static function representativeOf(OccurrenceCluster $cluster): CalendarEventOccurrence
    {
        return $cluster->extracted() ?? $cluster->primary;
    }

    /**
     * The row's Font Awesome icon.
     *
     * Here rather than in the template so the topbar trigger and the panel
     * cannot disagree about what a kindless event looks like — they read the
     * same method, and a `row.kind ? … : …` written twice is two answers waiting
     * to drift apart.
     */
    public function icon(): string
    {
        return $this->kind?->icon() ?? self::DEFAULT_ICON;
    }

    /** The row's user-facing name, for the screen reader line. */
    public function transKey(): string
    {
        return $this->kind?->transKey() ?? self::DEFAULT_TRANS_KEY;
    }
}
