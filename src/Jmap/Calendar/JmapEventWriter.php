<?php

declare(strict_types=1);

namespace App\Jmap\Calendar;

use App\Domain\Enum\Calendar\EventStatus;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Jmap\Protocol\Exception\MethodException;
use App\Repository\Calendar\CalendarRepository;
use App\Service\Calendar\CalendarEventWriter;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a JMAP CalendarEvent/set object into a written event.
 *
 * What an event IS — the canonical JSCalendar object, the columns projected
 * from it, the occurrence rows a view reads — belongs to CalendarEventWriter,
 * shared with the web editor and the sync engine. Nothing here touches a
 * column: that is the whole reason the writer exists, and a JMAP client that
 * set `$title` without `jscalendar['title']` would produce an event that looked
 * right in the app and exported blank.
 *
 * What is left here is the protocol: reading a JSCalendar object off the wire,
 * refusing what cannot be stored faithfully, and mapping a JMAP patch onto the
 * writer's parameter list.
 *
 * **Unknown properties are refused, not dropped.** The vocabulary below is what
 * the writer can honour; anything else is `invalidProperties` naming it. A
 * client whose `participants` were silently discarded would believe it had
 * invited somebody, and there is no way for it to discover otherwise — the same
 * argument EmailFilterCompiler makes for refusing an unknown filter condition.
 *
 * The properties left out, and why:
 *
 *   participants  An RSVP is answered through the invite flow (InviteResponder),
 *                 which sends an iTIP reply. Accepting an attendee list here
 *                 would record answers nobody was told about.
 *   privacy       There is no writer parameter for it, and writing the column
 *                 directly is exactly what this class must not do.
 *   alerts, links Nowhere to project them from, and the writer rebuilds the
 *                 canonical object from the columns on every write — so they
 *                 would survive one save and vanish on the next.
 */
final class JmapEventWriter
{
    /**
     * JSCalendar's LocalDateTime (RFC 8984 §4.1.2): no offset, no trailing Z.
     *
     * Parsed strictly. "2026-06-02T09:00:00Z" looks like a courtesy to accept
     * and is not: it says UTC, the zone beside it says Europe/Berlin, and
     * guessing which one the client meant moves a meeting by hours in silence.
     */
    private const string LOCAL_DATE_TIME = 'Y-m-d\TH:i:s';

    /** What an event lasts when the client did not say. */
    private const string DEFAULT_DURATION = 'PT1H';

    /** The same, for one marked showWithoutTime. */
    private const string DEFAULT_ALL_DAY_DURATION = 'P1D';

    /**
     * Everything a create or an update may name. "@type" is accepted and
     * ignored: it is JSCalendar's discriminator and always "Event" here.
     *
     * @var list<string>
     */
    private const array WRITABLE = [
        '@type',
        'calendarId',
        'uid',
        'title',
        'description',
        'start',
        'duration',
        'timeZone',
        'showWithoutTime',
        'locations',
        'status',
        'recurrenceRules',
        'recurrenceOverrides',
    ];

    public function __construct(
        private readonly CalendarRepository $calendars,
        private readonly CalendarEventWriter $writer,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string,mixed> $create the JMAP CalendarEvent being created
     */
    public function create(User $user, array $create): CalendarEvent
    {
        $this->assertVocabulary($create);

        $calendar = $this->calendar($user, $create['calendarId'] ?? null);
        $event = new CalendarEvent();

        $uid = $create['uid'] ?? null;

        if (null !== $uid) {
            // Kept verbatim when the client supplies one: RFC 5546 already
            // decided what an event's identity is, and a server that reissued
            // it would break every reply and update sent about the meeting.
            $event->uid = $this->string($uid, 'uid');
        }

        $this->apply($event, $calendar, $user, $create, $create);

        // Before the id can be read back. The set method flushes again at the
        // end of the call; this one is what mints the id it reports.
        $this->entityManager->flush();

        return $event;
    }

    /**
     * Rewrites an existing event from a JMAP patch.
     *
     * Only the properties the patch names; everything else is re-supplied from
     * what is stored. The writer derives the canonical object from its
     * arguments, so an absent `description` passed as null would delete a
     * description the client never mentioned.
     *
     * @param array<string,mixed> $patch
     */
    public function update(User $user, CalendarEvent $event, array $patch): void
    {
        $this->assertVocabulary($patch);

        if (true === array_key_exists('uid', $patch)) {
            throw new MethodException('invalidProperties', 'The uid is the event\'s identity and cannot be changed.', ['properties' => ['uid']]);
        }

        $calendar = true === array_key_exists('calendarId', $patch)
            ? $this->calendar($user, $patch['calendarId'])
            : $this->assertWritable($event->calendar);

        $this->apply($event, $calendar, $user, $patch, $this->stored($event));
    }

    /**
     * A patch key naming a path — "locations/1/name" — rather than a property.
     *
     * RFC 8620 §5.3 allows a PatchObject to address inside an object, and this
     * server does not implement it: the writer takes whole values, so a partial
     * one would have to be merged here against a stored object the writer is
     * about to rebuild, and the two merges would disagree the first time a
     * projection changed. Refused by name so a client can send the whole
     * property instead, which it always can.
     *
     * @param array<string,mixed> $properties
     */
    private function assertVocabulary(array $properties): void
    {
        $unknown = [];

        foreach (array_keys($properties) as $property) {
            $property = (string) $property;

            if (true === str_contains($property, '/')) {
                throw new MethodException('invalidPatch', sprintf('Patch paths are not supported; send the whole "%s" property.', explode('/', $property)[0]));
            }

            if (false === in_array($property, self::WRITABLE, true)) {
                $unknown[] = $property;
            }
        }

        if ([] !== $unknown) {
            throw new MethodException(
                'invalidProperties',
                sprintf('Not settable on a CalendarEvent: %s.', implode(', ', $unknown)),
                ['properties' => $unknown],
            );
        }
    }

    /**
     * One write, from a patch layered over a set of defaults.
     *
     * $defaults is the create object itself on a create — where an absent
     * property means its default — and the stored event's own values on an
     * update, where an absent property means "leave it alone". Expressing both
     * through one call is what keeps a create and an update from disagreeing
     * about what, say, an absent duration means.
     *
     * @param array<string,mixed> $patch
     * @param array<string,mixed> $defaults
     */
    private function apply(CalendarEvent $event, Calendar $calendar, User $user, array $patch, array $defaults): void
    {
        $values = array_merge($defaults, $patch);

        $isAllDay = $this->bool($values['showWithoutTime'] ?? false, 'showWithoutTime');
        $zoneName = $this->zoneName($values, $calendar);
        // A floating event has no zone to be local to, so its start is read as
        // UTC — which is what the entity means by "local midnight with a null
        // timeZone" and what the writer stores for an all-day event.
        $zone = $this->zone($zoneName ?? 'UTC');

        $startsAt = $this->start($values['start'] ?? null, $zone);
        $endsAt = $startsAt->add($this->duration(
            $values['duration'] ?? (true === $isAllDay ? self::DEFAULT_ALL_DAY_DURATION : self::DEFAULT_DURATION),
        ));

        $this->writer->write(
            event:          $event,
            calendar:       $calendar,
            user:           $user,
            title:          $this->string($values['title'] ?? '', 'title'),
            startsAt:       $startsAt,
            endsAt:         $endsAt,
            timeZone:       $zoneName,
            isAllDay:       $isAllDay,
            location:       $this->location($values['locations'] ?? null),
            description:    $this->stringOrNull($values['description'] ?? null, 'description'),
            status:         $this->status($values['status'] ?? null),
            recurrenceRule: $this->recurrenceRule($values['recurrenceRules'] ?? null),
        );

        // After write(), which rebuilds the canonical object and carries the
        // stored overrides across. A JMAP property is replaced whole, so the
        // patch is the entire truth about which instances differ — which is
        // exactly what $replaceExisting means to the writer, and the only way
        // an instance moved back stops being drawn where it used to be.
        if (true === array_key_exists('recurrenceOverrides', $patch)) {
            $this->writer->overrideInstances($event, $this->overrides($patch['recurrenceOverrides']), true);
        }
    }

    /**
     * The values a patch is layered over on an update: the event as it stands,
     * read back in the shape the wire uses.
     *
     * @return array<string,mixed>
     */
    private function stored(CalendarEvent $event): array
    {
        $zoneName = $event->timeZone;
        $startsAt = $event->startsAt;
        $endsAt = $event->endsAt;

        if (null === $startsAt || null === $endsAt) {
            throw new MethodException('serverFail', 'This event has no start; it cannot be patched.');
        }

        $local = $startsAt->setTimezone($this->zone($zoneName ?? 'UTC'));

        return [
            'title' => (string) $event->title,
            'start' => $local->format(self::LOCAL_DATE_TIME),
            'duration' => $this->writer->isoDuration($endsAt->getTimestamp() - $startsAt->getTimestamp()),
            'timeZone' => $zoneName,
            'showWithoutTime' => $event->isAllDay,
            'description' => $event->jscalendar['description'] ?? null,
            'locations' => $event->jscalendar['locations'] ?? null,
            'status' => $event->status->value,
            'recurrenceRules' => $event->jscalendar['recurrenceRules'] ?? null,
        ];
    }

    /**
     * The calendar a write lands on, owned by this user and accepting writes.
     *
     * A calendar belonging to somebody else is reported as notFound rather than
     * forbidden: the two are distinguishable, and only one of them tells a
     * stranger that the id exists.
     */
    private function calendar(User $user, mixed $calendarId): Calendar
    {
        if (false === is_string($calendarId) && false === is_int($calendarId)) {
            throw new MethodException('invalidProperties', 'A "calendarId" is required.', ['properties' => ['calendarId']]);
        }

        $id = (string) $calendarId;

        if (false === ctype_digit($id)) {
            throw new MethodException('invalidProperties', 'A "calendarId" is required.', ['properties' => ['calendarId']]);
        }

        $calendar = $this->calendars->findOneForUser($user, (int) $id);

        if (null === $calendar) {
            throw new MethodException('notFound', sprintf('No calendar "%s".', $id));
        }

        return $this->assertWritable($calendar);
    }

    /**
     * A mirror of somewhere that does not accept writes back refuses every
     * write, here rather than at the remote.
     *
     * The alternative is to accept the edit, mark it pending, and watch the
     * pusher discard it — which CalendarPusher does — leaving a client that was
     * told "updated" showing an event the next pull silently reverts. Refusing
     * is the only answer the client can act on.
     */
    private function assertWritable(?Calendar $calendar): Calendar
    {
        if (null === $calendar) {
            throw new MethodException('notFound', 'This event has no calendar.');
        }

        if (true === $calendar->isReadOnly) {
            throw new MethodException('forbidden', sprintf('Calendar "%s" is read-only; it mirrors somewhere that does not accept writes.', (string) $calendar->id));
        }

        return $calendar;
    }

    /**
     * The zone the start is local to, or null for a floating event.
     *
     * An absent timeZone and an explicit null are different answers and are
     * told apart here: absent means "you decide", and the calendar's own zone
     * is the only sensible decision; null is JSCalendar's floating time
     * (RFC 8984 §4.1.2), which an all-day event is. Reading `?? null` would
     * collapse the two and quietly zone every floating event a client sent.
     *
     * @param array<string,mixed> $values
     */
    private function zoneName(array $values, Calendar $calendar): ?string
    {
        if (false === array_key_exists('timeZone', $values)) {
            return $calendar->timeZone;
        }

        if (null === $values['timeZone']) {
            return null;
        }

        return $this->string($values['timeZone'], 'timeZone');
    }

    private function zone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name);
        } catch (\Exception) {
            throw new MethodException('invalidProperties', sprintf('"%s" is not an IANA time zone.', $name), ['properties' => ['timeZone']]);
        }
    }

    private function start(mixed $start, DateTimeZone $zone): DateTimeImmutable
    {
        $value = $this->string($start ?? '', 'start');
        $parsed = DateTimeImmutable::createFromFormat(self::LOCAL_DATE_TIME, $value, $zone);

        // createFromFormat is forgiving about trailing text and about a date
        // that does not exist; comparing the reformatted result back is what
        // makes "2026-02-30T09:00:00" a refusal rather than the 2nd of March.
        if (false === $parsed instanceof DateTimeImmutable || $parsed->format(self::LOCAL_DATE_TIME) !== $value) {
            throw new MethodException(
                'invalidProperties',
                sprintf('"start" must be a JSCalendar LocalDateTime (%s), with no offset and no trailing Z.', self::LOCAL_DATE_TIME),
                ['properties' => ['start']],
            );
        }

        return $parsed;
    }

    private function duration(mixed $duration): DateInterval
    {
        $value = $this->string($duration, 'duration');

        try {
            return new DateInterval($value);
        } catch (\Exception) {
            throw new MethodException('invalidProperties', sprintf('"%s" is not an ISO 8601 duration.', $value), ['properties' => ['duration']]);
        }
    }

    /**
     * The one location name the columns can hold.
     *
     * CalendarEvent projects a single `location` and the writer rebuilds
     * `locations` from it, so a second entry would survive this write and
     * vanish on the next one. Refused rather than truncated, for the reason
     * every refusal here exists: a client cannot detect what it lost.
     */
    private function location(mixed $locations): ?string
    {
        if (null === $locations) {
            return null;
        }

        if (false === is_array($locations)) {
            throw new MethodException('invalidProperties', '"locations" must be a map of Location objects.', ['properties' => ['locations']]);
        }

        if (0 === count($locations)) {
            return null;
        }

        if (1 < count($locations)) {
            throw new MethodException('invalidProperties', 'Only one location is stored; send the one that matters.', ['properties' => ['locations']]);
        }

        $location = reset($locations);

        if (false === is_array($location)) {
            throw new MethodException('invalidProperties', '"locations" must be a map of Location objects.', ['properties' => ['locations']]);
        }

        foreach (array_keys($location) as $property) {
            if (false === in_array((string) $property, ['@type', 'name'], true)) {
                throw new MethodException('invalidProperties', sprintf('Only a location\'s name is stored; "%s" would not survive the next save.', (string) $property), ['properties' => ['locations']]);
            }
        }

        return $this->stringOrNull($location['name'] ?? null, 'locations');
    }

    private function status(mixed $status): EventStatus
    {
        if (null === $status) {
            return EventStatus::Confirmed;
        }

        $resolved = EventStatus::tryFrom($this->string($status, 'status'));

        if (null === $resolved) {
            throw new MethodException(
                'invalidProperties',
                sprintf('"status" must be one of: %s.', implode(', ', array_column(EventStatus::cases(), 'value'))),
                ['properties' => ['status']],
            );
        }

        return $resolved;
    }

    /**
     * The one recurrence rule the writer stores.
     *
     * A rule this server cannot convert to an RRULE is kept verbatim and
     * expands to a single occurrence — RecurrenceMaterialiser's contract, and
     * deliberately not re-decided here. Validating it a second time would mean
     * two grammars that agree until the day one of them is fixed.
     *
     * @return array<string,mixed>|null
     */
    private function recurrenceRule(mixed $rules): ?array
    {
        if (null === $rules) {
            return null;
        }

        if (false === is_array($rules)) {
            throw new MethodException('invalidProperties', '"recurrenceRules" must be an array.', ['properties' => ['recurrenceRules']]);
        }

        if (0 === count($rules)) {
            return null;
        }

        if (1 < count($rules)) {
            throw new MethodException('invalidProperties', 'Only one recurrence rule is stored.', ['properties' => ['recurrenceRules']]);
        }

        $rule = reset($rules);

        if (false === is_array($rule)) {
            throw new MethodException('invalidProperties', '"recurrenceRules" must hold RecurrenceRule objects.', ['properties' => ['recurrenceRules']]);
        }

        return $rule;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function overrides(mixed $overrides): array
    {
        if (false === is_array($overrides)) {
            throw new MethodException('invalidProperties', '"recurrenceOverrides" must be a map keyed by LocalDateTime.', ['properties' => ['recurrenceOverrides']]);
        }

        $patches = [];

        foreach ($overrides as $key => $patch) {
            $key = (string) $key;

            if (false === is_array($patch)) {
                throw new MethodException('invalidProperties', sprintf('The override at "%s" must be an object.', $key), ['properties' => ['recurrenceOverrides']]);
            }

            $patches[$key] = $patch;
        }

        return $patches;
    }

    private function bool(mixed $value, string $property): bool
    {
        if (false === is_bool($value)) {
            throw new MethodException('invalidProperties', sprintf('"%s" must be a boolean.', $property), ['properties' => [$property]]);
        }

        return $value;
    }

    private function string(mixed $value, string $property): string
    {
        if (false === is_string($value) || '' === $value) {
            throw new MethodException('invalidProperties', sprintf('"%s" must be a non-empty string.', $property), ['properties' => [$property]]);
        }

        return $value;
    }

    private function stringOrNull(mixed $value, string $property): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (false === is_string($value)) {
            throw new MethodException('invalidProperties', sprintf('"%s" must be a string or null.', $property), ['properties' => [$property]]);
        }

        return $value;
    }
}
