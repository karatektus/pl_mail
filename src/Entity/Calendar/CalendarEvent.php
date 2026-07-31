<?php

declare(strict_types=1);

namespace App\Entity\Calendar;

use App\Domain\Enum\Calendar\EventPrivacy;
use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One event, stored as JSCalendar (RFC 8984) with the queryable parts lifted
 * into real columns.
 *
 * The hybrid is deliberate and is the biggest decision in this feature.
 *
 * JSCalendar is canonical because everything this calendar will ever talk to
 * speaks it or converts cleanly to it — iCalendar in both directions, CalDAV,
 * and JMAP calendars when that draft lands. A bespoke schema would be re-derived
 * badly the first time an .ics round-trips: participants, alerts, links and
 * recurrenceOverrides have nowhere to live, and losing them on import→export is
 * silent, which is the worst kind of data loss.
 *
 * But Postgres cannot do range logic on `"duration": "PT1H"`. So anything a
 * query filters, sorts or joins on is a column, and $jscalendar holds the truth
 * those columns are projected from. Writers go through CalendarEventWriter
 * rather than setting both by hand.
 *
 * Timestamps are UTC in a plain `timestamp` with the IANA zone beside them,
 * rather than `timestamptz`. That matches every other timestamp in this app,
 * matches how CalDAV and sabre model it, and avoids Doctrine's lossy
 * datetimetz read on Postgres. All-day events are floating: local midnight with
 * a null timeZone.
 */
#[ORM\Entity(repositoryClass: CalendarEventRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'calendar_event')]
#[ORM\Index(name: 'idx_calendar_event_usr', columns: ['usr_id'])]
#[ORM\Index(name: 'idx_calendar_event_calendar_starts', columns: ['calendar_id', 'starts_at'])]
#[ORM\Index(name: 'idx_calendar_event_uid', columns: ['uid'])]
#[ORM\Index(name: 'idx_calendar_event_kind', columns: ['kind'])]
#[ORM\UniqueConstraint(name: 'uniq_calendar_event_calendar_uid', columns: ['calendar_id', 'uid'])]
class CalendarEvent
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Calendar::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Calendar $calendar = null;

    /**
     * Denormalised from the calendar, because every read in this application is
     * scoped to a user and the alternative is joining calendar into the hot
     * range query for nothing.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $usr = null;

    /**
     * The iCalendar/JSCalendar UID, unique within its calendar — the identity a
     * later message updates, cancels or duplicates against.
     *
     * For an invite this is the sender's own UID, verbatim, because RFC 5546
     * already decided what identity means and re-deciding it would break
     * interoperability with every other calendar.
     */
    #[ORM\Column(length: 255)]
    public string $uid = '';

    /** iCalendar SEQUENCE: which revision this is. Higher wins. */
    #[ORM\Column(options: ['default' => 0])]
    public int $sequence = 0;

    /** UTC. Local midnight when isAllDay. */
    #[ORM\Column]
    public ?DateTimeImmutable $startsAt = null;

    /** UTC, exclusive. */
    #[ORM\Column]
    public ?DateTimeImmutable $endsAt = null;

    /** IANA zone; null means floating (all-day, or a genuinely zoneless event). */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $timeZone = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $isAllDay = false;

    /** Projected from jscalendar.title for lists and search. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $title = null;

    /** Projected from the first jscalendar.locations entry. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $location = null;

    #[ORM\Column(length: 16, enumType: EventStatus::class, options: ['default' => 'confirmed'])]
    public EventStatus $status = EventStatus::Confirmed;

    #[ORM\Column(length: 16, enumType: EventPrivacy::class, options: ['default' => 'public'])]
    public EventPrivacy $privacy = EventPrivacy::Public;

    /**
     * What this event is about, when it was extracted from mail. Null means a
     * person made it — which is also what "Happening Soon" filters on, so the
     * feature needs no table of its own.
     */
    #[ORM\Column(length: 24, nullable: true, enumType: ExtractionKind::class)]
    public ?ExtractionKind $kind = null;

    #[ORM\Column(length: 20, enumType: EventSource::class, options: ['default' => 'manual'])]
    public EventSource $source = EventSource::Manual;

    /** 0-100. Only meaningful for extracted events; manual is always 100. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 100])]
    public int $confidence = 100;

    #[ORM\Column(options: ['default' => false])]
    public bool $isRecurring = false;

    /**
     * The last moment this event can possibly occur, or null for a recurrence
     * with no end. Occurrences are materialised only to a bounded horizon, so
     * this is what the nightly sweep looks at to decide whose horizon needs
     * extending.
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $recurrenceUntil = null;

    /**
     * Set the moment a person edits an extracted event. The reconciler then
     * refuses to overwrite it: a later mail may know more about the booking,
     * but it does not know more than the user.
     */
    #[ORM\Column(options: ['default' => false])]
    public bool $isUserEdited = false;

    /**
     * Which formula produced the dedup key on this event's source links.
     *
     * Changing how a key is derived orphans every event already keyed the old
     * way — an update arrives, matches nothing, and becomes a duplicate. The
     * column costs a smallint now and makes that a re-keying backfill later
     * rather than a data-loss event.
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    public int $dedupKeyVersion = 1;

    /**
     * The canonical JSCalendar object. Everything above is projected from here.
     *
     * @var array<string,mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    public array $jscalendar = [];

    /** Opaque id at the remote, for events mirrored from a connected calendar. */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $remoteId = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $remoteEtag = null;

    /** @var Collection<int, CalendarEventOccurrence> */
    #[ORM\OneToMany(targetEntity: CalendarEventOccurrence::class, mappedBy: 'event', orphanRemoval: true)]
    public private(set) Collection $occurrences;

    /** @var Collection<int, EventSourceLink> */
    #[ORM\OneToMany(targetEntity: EventSourceLink::class, mappedBy: 'event', orphanRemoval: true)]
    public private(set) Collection $sourceLinks;

    public function __construct()
    {
        $this->occurrences = new ArrayCollection();
        $this->sourceLinks = new ArrayCollection();
    }

    /** Whether a person, rather than an extractor, is responsible for this. */
    public function isExtracted(): bool
    {
        return null !== $this->kind;
    }
}
