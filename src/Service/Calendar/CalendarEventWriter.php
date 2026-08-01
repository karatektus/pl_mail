<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\Enum\Calendar\EventStatus;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place an event is written, so the canonical JSCalendar object and the
 * columns projected from it cannot disagree.
 *
 * Without this there are two truths: a caller sets $title and forgets
 * jscalendar['title'], the calendar looks right, and the .ics export is blank.
 * Every writer — the controller now, extraction and CalDAV sync later — goes
 * through here, and re-materialises occurrences as part of the same call
 * because an event whose rows are stale is an event that is not in the view.
 *
 * Does not flush; it joins the caller's unit of work.
 */
final readonly class CalendarEventWriter
{
    public function __construct(
        private RecurrenceMaterialiser $materialiser,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string,mixed>|null $recurrenceRule    a JSCalendar RecurrenceRule, or null for a one-off
     * @param array<string,mixed>|null $jscalendarOverlay a canonical object an extractor already built
     */
    public function write(
        CalendarEvent     $event,
        Calendar          $calendar,
        User              $user,
        string            $title,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?string           $timeZone,
        bool              $isAllDay = false,
        ?string           $location = null,
        ?string           $description = null,
        EventStatus       $status = EventStatus::Confirmed,
        ?array            $recurrenceRule = null,
        ?array            $jscalendarOverlay = null,
    ): CalendarEvent {
        $event->calendar = $calendar;
        $event->usr      = $user;
        $event->title    = $title;
        $event->location = $location;
        $event->status   = $status;
        $event->isAllDay = $isAllDay;
        $event->timeZone = true === $isAllDay ? null : $timeZone;
        $event->startsAt = $startsAt->setTimezone(new DateTimeZone('UTC'));
        $event->endsAt   = $endsAt->setTimezone(new DateTimeZone('UTC'));

        if ('' === $event->uid) {
            $event->uid = $this->newUid();
        }

        $event->jscalendar = $this->toJsCalendar($event, $description, $recurrenceRule);

        // An extractor has already built the canonical object, and it carries
        // things no parameter list should have to thread through —
        // participants, alerts, the sender's own recurrence rule. It wins,
        // with the derived version as the floor. Merged HERE rather than by
        // the caller afterwards so the event is complete before occurrences
        // are materialised from it.
        if (null !== $jscalendarOverlay) {
            $event->jscalendar = array_merge($event->jscalendar, $jscalendarOverlay);
        }

        $this->em->persist($event);

        // Occurrences are what a view reads, so they are not optional and not
        // something a caller can forget: the event is not visible until they
        // exist, and stale ones are worse than none.
        $this->materialiser->materialise($event);

        return $event;
    }

    /**
     * A user edit is a decision. Recording it is what stops a later message
     * about the same booking quietly reverting what the user just fixed.
     */
    public function markUserEdited(CalendarEvent $event): void
    {
        if (true === $event->isExtracted()) {
            $event->isUserEdited = true;
        }
    }

    /**
     * @param array<string,mixed>|null $recurrenceRule
     *
     * @return array<string,mixed>
     */
    private function toJsCalendar(
        CalendarEvent $event,
        ?string       $description,
        ?array        $recurrenceRule,
    ): array {
        $zone = $event->timeZone ?? 'UTC';

        // JSCalendar times are LocalDateTime — no offset, no trailing Z — with
        // timeZone naming the zone they are local to (RFC 8984 §4.1.2).
        $local = $event->startsAt->setTimezone(new DateTimeZone($zone));

        $jscalendar = [
            '@type'    => 'Event',
            'uid'      => $event->uid,
            'title'    => (string) $event->title,
            'start'    => $local->format('Y-m-d\TH:i:s'),
            'duration' => $this->duration($event),
            'status'   => $event->status->value,
            'privacy'  => $event->privacy->value,
        ];

        if (null !== $event->timeZone) {
            $jscalendar['timeZone'] = $event->timeZone;
        }

        if (true === $event->isAllDay) {
            $jscalendar['showWithoutTime'] = true;
        }

        if (null !== $description && '' !== $description) {
            $jscalendar['description'] = $description;
        }

        if (null !== $event->location && '' !== $event->location) {
            $jscalendar['locations'] = [
                '1' => ['@type' => 'Location', 'name' => $event->location],
            ];
        }

        if (null !== $recurrenceRule) {
            $jscalendar['recurrenceRules'] = [$recurrenceRule];
        }

        // Overrides survive an edit: they are per-instance decisions the user
        // made, and rewriting the series is not a reason to lose them.
        if (true === isset($event->jscalendar['recurrenceOverrides'])) {
            $jscalendar['recurrenceOverrides'] = $event->jscalendar['recurrenceOverrides'];
        }

        return $jscalendar;
    }

    /** ISO 8601 duration, which is how JSCalendar says how long something is. */
    private function duration(CalendarEvent $event): string
    {
        $seconds = max(0, $event->endsAt->getTimestamp() - $event->startsAt->getTimestamp());

        if (0 === $seconds) {
            return 'PT0S';
        }

        $days    = intdiv($seconds, 86400);
        $hours   = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest    = $seconds % 60;

        $duration = 'P' . (0 < $days ? $days . 'D' : '');
        $time     = (0 < $hours ? $hours . 'H' : '')
            . (0 < $minutes ? $minutes . 'M' : '')
            . (0 < $rest ? $rest . 'S' : '');

        return '' === $time ? $duration : $duration . 'T' . $time;
    }

    /**
     * Globally unique and ours. The domain part is a literal rather than the
     * install's hostname on purpose: a UID must not change when someone puts
     * the app behind a different name, because it is the identity every other
     * calendar matches this event on.
     */
    private function newUid(): string
    {
        return bin2hex(random_bytes(16)) . '@plmail';
    }
}
