<?php

declare(strict_types=1);

namespace App\Entity\Calendar;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarAlertDeliveryRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * That one alert, on one occurrence, has already gone off.
 *
 * **The reason this is a table and not a flag.** An alert must fire exactly
 * once, and every way of losing that guarantee is a way that happens in
 * production rather than in theory: two sweeps overlapping because the first ran
 * long, a scheduler replaying a missed run when the worker comes back, a
 * container killed between sending the push and writing down that it sent one.
 * A column on the event cannot express "the 09:00 instance was alerted and the
 * 09:00 one tomorrow was not", and a comment saying not to send twice is not a
 * guarantee. A row with a unique constraint is, because the database refuses the
 * second insert whatever raced whatever.
 *
 * **Keyed by the occurrence's recurrence id, never by the occurrence's own row
 * id.** RecurrenceMaterialiser rewrites an event's occurrence rows wholesale on
 * every write, so a foreign key to one would cascade this record away every time
 * somebody corrected a title — and the alert would go off again. The recurrence
 * id is the instance's identity: where the rule put it, before anything moved
 * it, and stable across every re-materialisation.
 *
 * **Written by raw INSERT … ON CONFLICT DO NOTHING**, not through the ORM — see
 * CalendarAlertDeliveryRepository::claim(). That is why the timestamps are
 * supplied by the statement rather than by the lifecycle callbacks, and why
 * nothing outside a test ever hydrates one of these. The entity exists so the
 * table is part of the mapping and stays in the schema.
 *
 * The rows are pruned rather than kept forever: a daily standup with two alerts
 * is seven hundred rows a year, and a record older than the sweep's own lookback
 * window can never be consulted again.
 */
#[ORM\Entity(repositoryClass: CalendarAlertDeliveryRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'calendar_alert_delivery')]
// The guarantee itself. Event leads because every read and every write of this
// table is about one event's alerts — the claim names all three columns, and the
// prune goes by trigger_at below rather than through here.
#[ORM\UniqueConstraint(
    name: 'uniq_calendar_alert_delivery_event_alert_instance',
    columns: ['event_id', 'alert_key', 'recurrence_id'],
)]
// What the prune reads. Its own index because it is the one query that is not
// scoped to an event, and without it a nightly DELETE walks the whole table.
#[ORM\Index(name: 'idx_calendar_alert_delivery_trigger', columns: ['trigger_at'])]
class CalendarAlertDelivery
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /**
     * Non-nullable and without a default, unlike the associations on
     * CalendarEvent and its occurrences.
     *
     * Those are nullable in PHP because the editor and the extractors build one
     * field at a time; nothing builds one of these. A row either exists — with
     * all five of its facts — or it does not, and reading a half-built one
     * throws, which is the right answer to a genuine mistake.
     */
    #[ORM\ManyToOne(targetEntity: CalendarEvent::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public CalendarEvent $event;

    /**
     * Denormalised from the event, like everywhere else in this feature: the
     * delivery paths ask "whose alert is this?" and nothing else, and joining
     * the event back in to answer it would be a join per notification.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    /**
     * The key this alert lives under in `jscalendar.alerts`.
     *
     * Derived from the trigger and the action rather than minted — see
     * AlertReader. An alert whose key changed because an unrelated field was
     * edited would be an alert with no record, and therefore one that fires
     * again.
     */
    #[ORM\Column(length: 255)]
    public string $alertKey = '';

    /**
     * Which occurrence this was: its ORIGINAL start, UTC, exactly as
     * CalendarEventOccurrence::$recurrenceId holds it.
     */
    #[ORM\Column]
    public DateTimeImmutable $recurrenceId;

    /**
     * When the alert was due, which is not when it was sent.
     *
     * Kept because it is what the prune goes by, and because the difference
     * between it and createdAt is the only way to see that a sweep is running
     * late — a fact that presents to a user as "the reminder came after the
     * meeting started" and to a log as nothing at all.
     */
    #[ORM\Column]
    public DateTimeImmutable $triggerAt;
}
