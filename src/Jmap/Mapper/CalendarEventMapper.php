<?php

declare(strict_types=1);

namespace App\Jmap\Mapper;

use App\Domain\Enum\Calendar\EventStatus;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Jmap\Calendar\OccurrenceId;
use App\Jmap\Protocol\Exception\MethodException;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\RecurrenceMaterialiser;
use App\Service\Calendar\RecurrenceRuleConverter;

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
 *
 * **One dated instance is the exception, and it is the only one.** A client that
 * asked `CalendarEvent/query` to expand recurrences gets ids naming instances
 * (OccurrenceId), and an instance has no stored object to publish — the series'
 * row is all there is, and `calendar_event_occurrence` is where this application
 * already decided when that instance actually happens. So toJmapInstance()
 * derives from those columns exactly the values the row is the authority on —
 * `start`, `duration`, `recurrenceId` and a cancelled `status` — and nothing
 * else. The materialiser resolved every one of them in the series' own zone,
 * across DST, from the override; re-deriving them from the rule here is the
 * drift this class otherwise exists to prevent.
 */
final class CalendarEventMapper
{
    /**
     * JSCalendar's LocalDateTime (RFC 8984 §4.1.2): no offset, no trailing Z.
     * The same spelling RecurrenceRuleConverter::overrideKey() writes, because
     * `start` and `recurrenceId` are read in the same zone the keys are.
     */
    private const string LOCAL_DATE_TIME = 'Y-m-d\TH:i:s';

    public function __construct(
        private readonly RecurrenceMaterialiser $materialiser,
        private readonly RecurrenceRuleConverter $recurrence,
        private readonly CalendarEventWriter $writer,
    ) {
    }

    /**
     * @param list<string>|null $properties
     *
     * @return array<string,mixed>
     */
    public function toJmap(CalendarEvent $event, ?array $properties = null): array
    {
        return $this->select($this->full($event), $properties);
    }

    /**
     * One occurrence as a JSCalendar Event object, in the shape
     * draft-ietf-jmap-calendars asks for: the series with its overrides
     * resolved, `start` and `recurrenceId` set, and `recurrenceRules` and
     * `recurrenceOverrides` null — the draft says MUST for those two, and the
     * reason is sound. They describe a series, and a client that expanded them
     * again after asking the server to expand would draw the whole series once
     * per instance.
     *
     * The stored patch is merged over the series first, so an instance somebody
     * renamed comes back with its own title. Merged whole rather than key by
     * key: a patch is the client's own JSON, and picking the four keys this
     * server understands out of it would silently drop the rest — the same
     * quiet loss the filter refusals exist to prevent. What the row knows then
     * wins over what the patch says, because the row is the patch already read
     * (RecurrenceMaterialiser::applyOverride), in the series' zone, with the
     * DST arithmetic done.
     *
     * `seriesId` is a plMail extension and it is load-bearing: an instance id
     * is not something `CalendarEvent/set` accepts — writing one instance is a
     * `recurrenceOverrides` patch on the series — so without it a client that
     * expanded a query would hold ids it cannot edit through and no legal way
     * to get back to one it can. The draft has no answer here because it
     * expects `/set` to resolve synthetic ids itself, which this server does
     * not do.
     *
     * @param list<string>|null $properties
     *
     * @return array<string,mixed>
     */
    public function toJmapInstance(CalendarEventOccurrence $occurrence, ?array $properties = null): array
    {
        $event = $occurrence->event;
        $recurrenceId = $occurrence->recurrenceId;
        $startsAt = $occurrence->startsAt;
        $endsAt = $occurrence->endsAt;

        // Every one of those columns is NOT NULL, and the properties are
        // nullable only because Doctrine constructs the object before it fills
        // them in. serverFail rather than notFound: the id named a row that is
        // there, and reporting it missing would send a client looking for a
        // client-side bug.
        if (null === $event || null === $recurrenceId || null === $startsAt || null === $endsAt) {
            throw new MethodException('serverFail', 'This occurrence has no times; it cannot be published.');
        }

        $zone = $this->materialiser->zoneOf($event);
        $key = $this->recurrence->overrideKey($recurrenceId, $zone);

        $instance = array_merge($this->full($event), $this->patch($event, $key));

        $instance['id'] = OccurrenceId::of((int) $event->id, $recurrenceId);
        $instance['seriesId'] = (string) $event->id;
        $instance['recurrenceId'] = $key;
        // The zone that key is local to, which is not always the event's own:
        // a floating series is expanded in UTC (RecurrenceMaterialiser::zoneOf),
        // and a client reading the key in the user's zone instead would name a
        // different instant.
        $instance['recurrenceIdTimeZone'] = $zone->getName();
        $instance['start'] = $startsAt->setTimezone($zone)->format(self::LOCAL_DATE_TIME);
        $instance['duration'] = $this->writer->isoDuration($endsAt->getTimestamp() - $startsAt->getTimestamp());
        $instance['recurrenceRules'] = null;
        $instance['recurrenceOverrides'] = null;

        // This instance alone is off, which is not the series' status and does
        // not become it. Spelled through the enum for the reason every status
        // here is: the value has to match what the writer projects, or a
        // cancelled instance reads as confirmed on the wire.
        if (true === $occurrence->cancelled) {
            $instance['status'] = EventStatus::Cancelled->value;
        }

        return $this->select($instance, $properties);
    }

    /**
     * The requested subset, or everything when nothing was asked for.
     *
     * @param array<string,mixed> $object
     * @param list<string>|null   $properties
     *
     * @return array<string,mixed>
     */
    private function select(array $object, ?array $properties): array
    {
        if (null === $properties) {
            return $object;
        }

        // "id" is always returned regardless of the requested property set.
        $filtered = ['id' => $object['id']];

        foreach ($properties as $property) {
            if (true === array_key_exists($property, $object)) {
                $filtered[$property] = $object[$property];
            }
        }

        return $filtered;
    }

    /**
     * The patch filed against one instance, or nothing.
     *
     * @return array<string,mixed>
     */
    private function patch(CalendarEvent $event, string $key): array
    {
        $overrides = $event->jscalendar['recurrenceOverrides'] ?? null;

        if (false === is_array($overrides)) {
            return [];
        }

        $patch = $overrides[$key] ?? null;

        return is_array($patch) ? $patch : [];
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
