<?php

declare(strict_types=1);

namespace App\Entity\Calendar;

use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventOccurrenceRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One dated instance of an event. The table a calendar view actually reads.
 *
 * Recurrence is the part of a calendar that naive designs get wrong, and there
 * are only three options:
 *
 *   Expand RRULEs in PHP when a view asks. To answer "what is in July" you must
 *   first load every recurring event ever created — a standup from 2019 still
 *   generates instances — and expand each one. Nothing is indexable, nothing is
 *   pageable, and free/busy becomes quadratic.
 *
 *   Materialise everything. FREQ=DAILY with no UNTIL has no end.
 *
 *   Materialise to a bounded horizon and fall back to live expansion outside
 *   it. That is this table. RecurrenceMaterialiser rewrites an event's rows on
 *   every write, and a nightly sweep rolls the horizon forward.
 *
 * $span is a generated tsrange rather than something the application writes, so
 * it cannot drift from the two columns it derives from. The month-view query is
 * an `&&` overlap against a GiST index on it — see the repository for why that
 * beats comparing starts_at and ends_at separately.
 *
 * idx_ceo_span is declared below as a plain index and created `USING gist` by
 * the migration. That is deliberate and is the same trick Message plays with
 * idx_message_search_vector, which is really GIN: Doctrine's comparator matches
 * an index on its name and columns and never looks at the method, so declaring
 * it keeps the mapping and the database agreeing while the migration decides
 * how it is actually built. Without the declaration every schema diff asks to
 * drop it, and dropping it turns every calendar view into a sequential scan
 * without failing anything.
 *
 * Non-recurring events get exactly one row here too. One code path for reads is
 * worth more than the rows saved by special-casing them.
 */
#[ORM\Entity(repositoryClass: CalendarEventOccurrenceRepository::class)]
#[ORM\Table(name: 'calendar_event_occurrence')]
#[ORM\Index(name: 'idx_ceo_usr_starts', columns: ['usr_id', 'starts_at'])]
#[ORM\Index(name: 'idx_ceo_calendar_starts', columns: ['calendar_id', 'starts_at'])]
// The alert sweep, and the only read of this table that is not scoped to an
// owner: it asks "what starts near now, anywhere on this install?" once a
// minute. Both indexes above lead with a user or a calendar and cannot answer
// that, so without a start-only index the sweep is a sequential scan of every
// occurrence ever materialised — every minute, on a table that holds a thousand
// rows per unbounded recurring event.
#[ORM\Index(name: 'idx_ceo_starts', columns: ['starts_at'])]
// Built `USING gist` by the migration — see the note above.
#[ORM\Index(name: 'idx_ceo_span', columns: ['calendar_id', 'span'])]
#[ORM\UniqueConstraint(name: 'uniq_ceo_event_recurrence', columns: ['event_id', 'recurrence_id'])]
class CalendarEventOccurrence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CalendarEvent::class, inversedBy: 'occurrences')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?CalendarEvent $event = null;

    /** Denormalised so a range read never joins back to the event. */
    #[ORM\ManyToOne(targetEntity: Calendar::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Calendar $calendar = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $usr = null;

    /**
     * This instance's ORIGINAL start, before any override moved it — the key
     * JSCalendar uses in recurrenceOverrides, and the only stable way to say
     * "the one that was meant to be on the 3rd" once it has been dragged to
     * the 5th.
     */
    #[ORM\Column]
    public ?DateTimeImmutable $recurrenceId = null;

    /** UTC, where this instance actually is. */
    #[ORM\Column]
    public ?DateTimeImmutable $startsAt = null;

    /** UTC, exclusive. */
    #[ORM\Column]
    public ?DateTimeImmutable $endsAt = null;

    /** This instance differs from the series' pattern. */
    #[ORM\Column(options: ['default' => false])]
    public bool $isOverride = false;

    /**
     * This instance alone is off. Kept rather than deleted for the same reason
     * a cancelled event is: the answer to "wasn't there something today?" is
     * more useful than a gap.
     */
    #[ORM\Column(options: ['default' => false])]
    public bool $cancelled = false;

    /**
     * The half-open interval this occupies, maintained by Postgres.
     *
     * Generated rather than written so it cannot disagree with the two columns
     * it derives from — the same treatment Message::$searchVector gets, and for
     * the same reason. Mapped read-only purely so the schema is complete;
     * nothing in PHP reads it, because the only thing that can use it is the
     * `&&` overlap in CalendarEventOccurrenceRepository.
     */
    #[ORM\Column(
        type: Types::TEXT,
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: "tsrange GENERATED ALWAYS AS (tsrange(starts_at, ends_at, '[)')) STORED",
    )]
    public private(set) ?string $span = null;
}
