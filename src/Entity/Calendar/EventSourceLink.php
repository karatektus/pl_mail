<?php

declare(strict_types=1);

namespace App\Entity\Calendar;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Repository\Calendar\EventSourceLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Which message put this event on the calendar, and what exactly it said.
 *
 * Many-to-many with metadata rather than a nullable message_id on the event,
 * because neither direction is single: one message can produce several events
 * (a two-leg flight, a multi-parcel order), and one event is typically produced
 * by several messages — a confirmation, then a change, then a cancellation,
 * usually spread across a thread.
 *
 * $payload is the load-bearing column. It stores the extracted fragment exactly
 * as it was read, so an extractor's inputs live next to its outputs. That is
 * what makes improving a mapper a backfill instead of a resync: the same
 * property MessageCategorizer has, and the reason it can re-derive every
 * category from stored rows without touching a mail server.
 *
 * $applied false means "this message was read, and lost" — a stale duplicate,
 * or an update that arrived after a newer one. Keeping it is what makes "why is
 * this on my calendar?" answerable.
 */
#[ORM\Entity(repositoryClass: EventSourceLinkRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'event_source_link')]
#[ORM\Index(name: 'idx_event_source_link_message', columns: ['message_id'])]
#[ORM\Index(name: 'idx_event_source_link_dedup', columns: ['dedup_key'])]
#[ORM\UniqueConstraint(name: 'uniq_event_source_link', columns: ['event_id', 'message_id', 'extractor'])]
class EventSourceLink
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CalendarEvent::class, inversedBy: 'sourceLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?CalendarEvent $event = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Message $message = null;

    /**
     * The text/calendar part it came out of, when it came out of one. SET NULL
     * rather than CASCADE: losing the part should not lose the provenance.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?MessagePart $messagePart = null;

    /** Which extractor produced this. Free text, matched only for display. */
    #[ORM\Column(length: 64)]
    public string $extractor = '';

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    public int $confidence = 0;

    /**
     * The identity this extraction claimed, before the event existed. Kept so a
     * re-key backfill can find the rows it needs to rewrite — see
     * CalendarEvent::$dedupKeyVersion.
     */
    #[ORM\Column(length: 128)]
    public string $dedupKey = '';

    /** False means read but superseded; the event does not reflect this row. */
    #[ORM\Column(options: ['default' => true])]
    public bool $applied = true;

    /**
     * The extracted fragment, verbatim.
     *
     * @var array<string,mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    public array $payload = [];
}
