<?php

declare(strict_types=1);

namespace App\Jmap\Mapper;

use App\Entity\Calendar\CalendarEvent;

/**
 * Maps a plMail CalendarEvent onto the JMAP CalendarEvent object.
 *
 * **A JMAP CalendarEvent id is a `calendar_event` row id — the series, not one
 * of its dated instances.** That is the id-space decision this whole feature
 * turns on, and it is not the obvious one: the query that finds events runs over
 * `calendar_event_occurrence`, so the ids the database hands back are occurrence
 * ids and something has to translate them. Both are autoincrement ints from
 * different tables, so an untranslated occurrence id does not fail — it is a
 * valid-looking id for an unrelated event, which a client fetches and renders.
 * That is exactly how Email.mailboxIds shipped label ids where binding ids were
 * meant (see EmailMapperTest), and the answer is the same: one id space
 * everywhere — `list[].id`, `/query`'s ids, `/get`'s ids, `/set`'s ids — and a
 * test that puts every emitted id back into a filter and checks it selects what
 * it came from.
 *
 * The series is the right unit because it is what a JSCalendar Event *is*
 * (RFC 8984): one object carrying `recurrenceRules` and `recurrenceOverrides`,
 * from which a client expands instances itself. An id per occurrence would name
 * rows that this application creates and destroys on every write — the
 * materialiser rewrites them wholesale — so a client's stored id would go stale
 * the moment somebody corrected a title.
 *
 * The object published IS the stored canonical JSCalendar object, plus the
 * envelope JMAP adds (`id`, `calendarId`, `created`, `updated`). Nothing is
 * re-derived from the projected columns here: CalendarEventWriter is the one
 * place that turns columns into JSCalendar, and a second derivation in the
 * mapper would be a second answer to what the event is — the exact drift the
 * writer exists to prevent. An event whose `jscalendar` is empty is therefore
 * published nearly empty, which is honest: no writer made that row.
 */
final class CalendarEventMapper
{
    /**
     * @param list<string>|null $properties
     *
     * @return array<string,mixed>
     */
    public function toJmap(CalendarEvent $event, ?array $properties = null): array
    {
        $full = $this->full($event);

        if (null === $properties) {
            return $full;
        }

        // "id" is always returned regardless of the requested property set.
        $filtered = ['id' => $full['id']];

        foreach ($properties as $property) {
            if (true === array_key_exists($property, $full)) {
                $filtered[$property] = $full[$property];
            }
        }

        return $filtered;
    }

    /**
     * @return array<string,mixed>
     */
    private function full(CalendarEvent $event): array
    {
        $jscalendar = $event->jscalendar;

        // The envelope overwrites, and must: `uid` is in the canonical object
        // too, and the column is what uniqueness is enforced on, so the two
        // disagreeing is a row the writer has to be told about rather than a
        // difference to publish.
        $jscalendar['@type'] = 'Event';
        $jscalendar['uid'] = $event->uid;
        $jscalendar['id'] = (string) $event->id;
        $jscalendar['calendarId'] = null === $event->calendar ? null : (string) $event->calendar->id;
        $jscalendar['sequence'] = $event->sequence;
        $jscalendar['created'] = $this->utcOrNull($event->createdAt);
        $jscalendar['updated'] = $this->utcOrNull($event->updatedAt);
        // Derived, and published because a client cannot see it from the rule
        // alone: a rule this server could not convert is stored verbatim and
        // expands to a single occurrence, so `recurrenceRules` being present is
        // not the same claim as "this recurs here".
        $jscalendar['isRecurring'] = $event->isRecurring;
        // plMail extension: what this event was extracted from, or null when a
        // person made it. It is what "Happening Soon" filters on, and a client
        // that could not tell a booking confirmation from a typed appointment
        // would have to guess from the title.
        $jscalendar['kind'] = $event->kind?->value;
        $jscalendar['source'] = $event->source->value;

        return $jscalendar;
    }

    private function utcOrNull(?\DateTimeImmutable $date): ?string
    {
        if (null === $date) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
