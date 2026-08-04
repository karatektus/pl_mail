<?php

declare(strict_types=1);

namespace App\Entity\Calendar;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Calendar\EventProposalRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A date somebody wrote in a sentence, offered to the user and standing on
 * nothing else until they say yes.
 *
 * A table of its own rather than a state on CalendarEvent, and that is the
 * whole point of the feature rather than a detail of it. CalendarEventWriter
 * materialises occurrences on every write, every range query reads occurrences,
 * and UpcomingEventIndicator lights the topbar dot from them — so an event row
 * is *visible* by construction, and keeping a guess in that table would make it
 * a view's job to remember to exclude it. One view forgetting once is an
 * invented flight time on somebody's calendar. A proposal materialises nothing
 * and therefore cannot leak: there is no occurrence to find.
 *
 * The other half of the same decision is what accepting means. It does not flip
 * a column here; it writes a CalendarEvent through CalendarEventWriter — the one
 * place an event is written — and this row goes. Refusing writes an
 * EventSuppression keyed on $dedupKeyHash, which is the mechanism dismissal
 * already uses for extracted events, so a backfill re-reading the same mail next
 * year proposes nothing.
 *
 * What is kept beyond the times is $sourceSentence, and it is not decoration.
 * A guess whose evidence is visible can be judged in a second; a bare date with
 * an Add button next to it cannot be judged at all, so it gets clicked or
 * ignored on a coin flip.
 *
 * Timestamps are UTC with the IANA zone beside them, as on CalendarEvent, for
 * the same reasons — the accepted event inherits both unchanged.
 */
#[ORM\Entity(repositoryClass: EventProposalRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'event_proposal')]
// One message may not propose the same instant twice. EventProposer asks
// before it writes, but that check is a read on data another worker may be
// about to change — a backfill running while mail arrives processes the same
// row from two directions — and this is the guard that actually holds. The
// message leads because every read of this table is "what does THIS message
// propose?": the card asks per conversation and the accept/dismiss actions are
// keyed on a message someone is looking at, so a message-first index answers
// both, and a starts_at-first one answers neither.
#[ORM\UniqueConstraint(name: 'uniq_event_proposal_message_starts_at', columns: ['message_id', 'starts_at'])]
// There is deliberately no index on usr_id beyond the one Doctrine creates for
// the foreign key, and no index on starts_at. Nothing sweeps this table by
// user or by date: a proposal is reached from the message that carries it and
// is deleted the moment it is answered, so the only access path is the unique
// constraint above. An index for a query nobody makes is a write cost on every
// ingest.
class EventProposal
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /**
     * Denormalised from the message's account, exactly as CalendarEvent
     * denormalises it from the calendar: a suppression is written per user, and
     * the accept path needs the owner without a join through account.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    /**
     * The mail this was read out of. Not nullable and cascading, because a
     * proposal without its message has lost the sentence that justifies it and
     * is then a bare date with an Add button — the thing this design exists to
     * avoid.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Message $message;

    /**
     * From the subject, stripped of Re:/Fwd:/AW:/WG:. Prose almost never names
     * the thing it is arranging — "Termin wie vereinbart: 04.08.2026 um 14 Uhr"
     * says when and nothing about what — and the subject line is what the user
     * already recognises the conversation by.
     */
    #[ORM\Column(type: Types::TEXT)]
    public string $title = '';

    /**
     * UTC, like every instant here.
     *
     * Typed without a default rather than nullable, and the same for the four
     * fields above it: a proposal that exists has all of them, and a reader
     * should not be made to check for a null the database cannot contain.
     * Reading one before the row is built throws, which is the right answer to
     * a genuine mistake — the rule TimestampableTrait states for its own
     * columns, applied here.
     */
    #[ORM\Column]
    public DateTimeImmutable $startsAt;

    /** UTC, exclusive. One hour after the start when the mail did not say. */
    #[ORM\Column]
    public DateTimeImmutable $endsAt;

    #[ORM\Column(options: ['default' => false])]
    public bool $isAllDay = false;

    /**
     * The wall clock the sentence was read in — the user's own, since a person
     * writing "um 14 Uhr" means their reader's afternoon and states no offset.
     * Carried through to the event on acceptance so the two agree.
     */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $timeZone = null;

    /**
     * 0-100, and never 100: this is a guess by construction. What it separates
     * is a fully stated date from a relative one — see DeterministicDateDetector,
     * which owns the two values and says what each means.
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    public int $confidence = 0;

    /**
     * The sentence this was read from, verbatim and clamped.
     *
     * The card quotes it, which is the only reason a person can judge the guess
     * without opening the mail and hunting for the line.
     */
    #[ORM\Column(type: Types::TEXT)]
    public string $sourceSentence = '';

    /**
     * sha256 of the claim's dedup key, hex — the same shape EventSuppression
     * stores and compares, so dismissing a proposal writes one of those rows
     * and the next detection run is refused before it builds anything.
     *
     * The hash rather than the key for the reason EventSuppression gives:
     * fixed width, and nothing needs the original back. The key is rebuilt from
     * the message and the instant whenever it is needed, by the one method that
     * knows the formula.
     */
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    public string $dedupKeyHash = '';

    /**
     * Which detector produced this.
     *
     * Stored because a second one is coming: the parser sits behind
     * ProposalDetectorInterface precisely so a model-backed detector can be
     * added later, and on the day it is, "which proposals did the new thing
     * make?" has to be answerable — to measure it, and to delete them all again
     * if it turns out to be worse than nothing.
     */
    #[ORM\Column(length: 32)]
    public string $detector = '';

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }
}
