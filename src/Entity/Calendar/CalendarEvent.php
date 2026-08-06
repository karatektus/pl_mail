<?php

declare(strict_types=1);

namespace App\Entity\Calendar;

use App\Domain\Enum\Calendar\EventPrivacy;
use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Domain\Enum\Calendar\SyncState;
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
// The pull's identity lookup: one row per remote resource within a calendar.
// Not unique, because remote_id is null on every locally-made event and on
// every event on a calendar that mirrors nothing — which is most of the table,
// and Postgres treats each null as distinct anyway, so a unique index here
// would buy nothing the lookup does not already get.
#[ORM\Index(name: 'idx_calendar_event_calendar_remote', columns: ['calendar_id', 'remote_id'])]
// What the pusher sweeps before every pull. Calendar leads because the sweep is
// always scoped to one calendar and the states are four values — leading with
// sync_state would put the whole table's clean rows in front of the answer.
#[ORM\Index(name: 'idx_calendar_event_calendar_sync_state', columns: ['calendar_id', 'sync_state'])]
// Which series a provider's instance id belongs to, asked once per tombstone
// the ordinary lookups could not place. Built `USING gin` by the migration and
// declared here as a plain index, the same trick CalendarEventOccurrence plays
// with its tsrange: the comparator matches an index on its name and columns and
// never looks at the method, so this keeps the mapping and the database
// agreeing while the migration decides how it is really built. Declared at all
// because without it every schema diff asks to drop it, and dropping it turns
// the lookup into a sequential scan of every event on the install.
#[ORM\Index(name: 'idx_calendar_event_remote_instances', columns: ['remote_instances'])]
#[ORM\UniqueConstraint(name: 'uniq_calendar_event_calendar_uid', columns: ['calendar_id', 'uid'])]
class CalendarEvent
{
    use TimestampableTrait;

    /**
     * How a $remoteInstances value spells an instant: UTC, ISO 8601, with the Z
     * on it.
     *
     * A contract between the puller that writes the map and the drivers that
     * read it, so it is named once rather than repeated at both ends. The Z is
     * not decoration — a value written without one is read back in whatever zone
     * the reader happens to hold, which for a Berlin calendar is an instance two
     * hours from the one that was recorded.
     */
    public const string INSTANCE_START_FORMAT = 'Y-m-d\TH:i:s\Z';

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
     * The owner's own answer to this invitation, and **whether it is drawn**.
     *
     * Projected out of `jscalendar.participants[me].participationStatus`, which
     * is where the answer actually lives, because a jsonb key named after the
     * reader's email address is not something any query can filter on and this
     * has to be filterable: RecurrenceMaterialiser refuses to write occurrences
     * for an invitation that is unanswered or declined, so an invitation appears
     * in the calendar when it is accepted or answered "maybe" and not before.
     * One rule in one place, and every reader — the views, the alert sweep,
     * Happening Soon, a share link, JMAP — follows from it without a clause of
     * its own.
     *
     * **Null is not "needs action".** It means this event is not an invitation
     * addressed to the owner at all, which is the ordinary case: something they
     * typed, a booking read out of a confirmation, a row mirrored from a
     * provider, or an invitation they themselves organised. Those are drawn
     * unconditionally, and conflating the two would empty the calendar.
     *
     * Set by EventReconciler when an invitation arrives and by InviteResponder
     * when one is answered. The reconciler NEVER downgrades an answer that has
     * already been given: an organiser's re-sent REQUEST carries the attendee
     * list as they last saw it, so a stale NEEDS-ACTION in it would silently
     * un-accept a meeting somebody is already going to.
     */
    #[ORM\Column(length: 16, nullable: true, enumType: ParticipationStatus::class)]
    public ?ParticipationStatus $myParticipation = null;

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

    /**
     * Which occurrence of this series each of the remote's own instance
     * resources is: the provider's opaque instance id, against the ORIGINAL
     * start of the occurrence it stands for, as a UTC instant.
     *
     * Empty for everything that is not a mirrored series, which is most of the
     * table, and empty for CalDAV however recurring the event is — a provider
     * whose instances live inside the master's own .ics has no instance id to
     * record.
     *
     * **It exists because Microsoft's tombstone carries an id and nothing
     * else.** A cancelled occurrence arrives from a calendarView delta as
     * `@removed` with the instance's id — not its series, not the start it had —
     * so without a record of what that id meant it matches no row, does nothing,
     * and the occurrence the user deleted in Outlook is drawn forever. This is
     * that record, and it is also what lets a push address an instance directly
     * instead of listing a window to find it.
     *
     * **Keyed by the id, not by the start,** although the push reads it in the
     * other direction. The one question that cannot be answered in PHP is "whose
     * instance is this id?" — it is asked against the whole table, from a
     * tombstone that names nothing else — and a jsonb key is what an index can
     * answer that with (`jsonb_exists`, and see the GIN index above). The
     * reverse lookup a push wants is a scan of one series' own map, which is
     * already in memory and never longer than the exceptions it has.
     *
     * **A column rather than a table**, and the trade is honest: a row per
     * instance would index better and would not rewrite a series' whole map to
     * add one id. But every read of this is a read of one event's own map by
     * something that already holds the event, the writes happen in the same unit
     * of work as the series, and the entries have no life of their own — so a
     * second table would be a join and a cascade for data that is strictly one
     * event's. The cost is that a weekly series pulled from Graph carries a
     * couple of hundred entries; CalendarPuller drops the ones older than the
     * horizon the occurrences are drawn to, because an id for an instance no
     * view can show answers no question.
     *
     * @var array<string,string>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    public array $remoteInstances = [];

    /**
     * Whether this row owes the remote a write. See SyncState — the marking is
     * CalendarEventWriter's, not a caller's.
     *
     * Clean on an event belonging to no remote calendar, which is most of them,
     * and the default exists so extraction and the editor keep working without
     * knowing this column is here.
     */
    #[ORM\Column(length: 20, enumType: SyncState::class, options: ['default' => 'clean'])]
    public SyncState $syncState = SyncState::Clean;

    /**
     * When the remote last accepted this row, or last gave it to us. Null means
     * the two have never agreed — a locally created event not yet pushed, or an
     * event on a calendar that mirrors nothing.
     *
     * Kept beside $syncState rather than derived from $updatedAt: the trait
     * bumps that on every write including the sync's own, so it can never
     * answer "how stale is this against the remote?".
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $syncedAt = null;

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

    /**
     * Whether an extractor, rather than a person, is responsible for this.
     *
     * Stays a method rather than becoming a property: $kind is an enum, and
     * reading "was this extracted" out of which kind it is — or out of the
     * absence of one — is an interpretation, not a plain read.
     */
    public function isExtracted(): bool
    {
        return null !== $this->kind;
    }
}
