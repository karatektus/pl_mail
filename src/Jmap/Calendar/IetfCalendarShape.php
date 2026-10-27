<?php

declare(strict_types=1);

namespace App\Jmap\Calendar;

use App\Jmap\Protocol\Exception\MethodException;

/**
 * plMail's calendar objects in the IETF draft's shape, and back.
 *
 * The calendar methods answer two URNs (see Capability::IETF_CALENDARS). Under
 * plMail's own, an event is filed by one `calendarId` and repeats by RFC 8984's
 * `recurrenceRules` list; the draft — and Stalwart, and every client written
 * against either — files it by a `calendarIds` map and repeats it by one
 * `recurrenceRule`. Translated here, at the edge, so the mapper and the writer
 * keep one vocabulary and the difference is one class that can be deleted with
 * the vendor URN.
 *
 * On the way in, a draft client sends the event as it last read it, server-set
 * properties and all, plus null for every property it holds none of — that is
 * how a draft update clears a location. JmapEventWriter refuses any property it
 * cannot store, and rightly; so what a draft client sends without meaning
 * anything by it is taken off first: properties only the server sets, and
 * properties plMail does not store whose value says nothing (null, empty, the
 * default, or what this server published). Anything else is left in for the
 * writer to refuse by name — a client that set alerts must hear they were not
 * kept.
 */
final class IetfCalendarShape
{
    /** plMail's name => the draft's, for the properties named differently. */
    private const array RENAMED = [
        'calendarId'      => 'calendarIds',
        'recurrenceRules' => 'recurrenceRule',
        'seriesId'        => 'baseEventId',
    ];

    /** Published by plMail beside JSCalendar, with nothing in the draft to match. */
    private const array PLMAIL_ONLY = ['isRecurring', 'kind', 'source', 'recurrenceIdTimeZone'];

    /** Set by the server; a client echoes them back, and they mean nothing on a write. */
    private const array SERVER_SET = [
        'id', 'created', 'updated', 'sequence', 'isDraft', 'isOrigin', 'utcStart', 'utcEnd',
        'baseEventId', 'blobId', 'prodId', 'isRecurring', 'kind', 'source', 'recurrenceIdTimeZone',
    ];

    /** What plMail does not store, by what it means when absent. Absent, null and these say nothing. */
    private const array UNSTORED_DEFAULTS = [
        'privacy'                 => 'public',
        'freeBusyStatus'          => 'busy',
        'useDefaultAlerts'        => false,
        'priority'                => 0,
        'excludedRecurrenceRules' => null,
        'alerts'                  => null,
        'participants'            => null,
        'keywords'                => null,
        'virtualLocations'        => null,
        'links'                   => null,
        'categories'              => null,
        'relatedTo'               => null,
        'localizations'           => null,
        'replyTo'                 => null,
        'locale'                  => null,
        'color'                   => null,
        'mayInviteSelf'           => false,
        'mayInviteOthers'         => false,
        'hideAttendees'           => false,
        'method'                  => null,
    ];

    /**
     * An event as this server publishes it, in the draft's shape.
     *
     * @param array<string,mixed> $event
     *
     * @return array<string,mixed>
     */
    public function event(array $event): array
    {
        if (true === array_key_exists('calendarId', $event)) {
            $event['calendarIds'] = null === $event['calendarId'] ? [] : [(string) $event['calendarId'] => true];
            unset($event['calendarId']);
        }

        if (true === array_key_exists('recurrenceRules', $event)) {
            $rules                   = $event['recurrenceRules'];
            $event['recurrenceRule'] = is_array($rules) && is_array($rules[0] ?? null) ? $rules[0] : null;
            unset($event['recurrenceRules']);
        }

        if (true === array_key_exists('seriesId', $event)) {
            $event['baseEventId'] = $event['seriesId'];
            unset($event['seriesId']);
        }

        return array_diff_key($event, array_flip(self::PLMAIL_ONLY));
    }

    /**
     * A calendar as this server publishes it, with the draft's rights beside
     * plMail's. The draft splits "write" by whose event it is; every event in
     * a plMail calendar is its owner's, so the two are one answer.
     *
     * @param array<string,mixed> $calendar
     *
     * @return array<string,mixed>
     */
    public function calendar(array $calendar): array
    {
        if (true === is_array($calendar['myRights'] ?? null)) {
            $rights = $calendar['myRights'];
            $write  = true === ($rights['mayUpdateAll'] ?? false);

            $calendar['myRights'] = $rights + [
                'mayReadFreeBusy'  => true === ($rights['mayReadItems'] ?? false),
                'mayWriteAll'      => $write,
                'mayWriteOwn'      => $write,
                'mayUpdatePrivate' => $write,
                'mayRSVP'          => false,
                'mayShare'         => false,
            ];
        }

        if (true === array_key_exists('isVisible', $calendar)) {
            $calendar['isSubscribed'] = true;
        }

        return $calendar;
    }

    /**
     * The property names a draft client asked for, in plMail's names; null
     * for all of them.
     *
     * @param list<string>|null $properties
     *
     * @return list<string>|null
     */
    public function properties(?array $properties): ?array
    {
        if (null === $properties) {
            return null;
        }

        $plmail = array_flip(self::RENAMED);

        return array_values(array_unique(array_map(static fn (string $name): string => $plmail[$name] ?? $name, $properties)));
    }

    /**
     * A draft create or update in the shape JmapEventWriter takes.
     *
     * @param array<string,mixed> $properties
     * @param array<string,mixed> $published  the event as this server would publish it, for an update; [] for a create
     *
     * @return array<string,mixed>
     *
     * @throws MethodException for calendarIds naming other than one calendar
     */
    public function write(array $properties, array $published = []): array
    {
        if (true === array_key_exists('calendarIds', $properties)) {
            $ids = array_keys(array_filter(is_array($properties['calendarIds']) ? $properties['calendarIds'] : []));

            if (1 !== count($ids)) {
                throw new MethodException('invalidProperties', 'An event is in exactly one calendar here: "calendarIds" must name one.', ['properties' => ['calendarIds']]);
            }

            $properties['calendarId'] = (string) $ids[0];
            unset($properties['calendarIds']);
        }

        if (true === array_key_exists('recurrenceRule', $properties)) {
            $rule                          = $properties['recurrenceRule'];
            $properties['recurrenceRules'] = is_array($rule) ? [$rule] : null;
            unset($properties['recurrenceRule']);
        }

        foreach ($properties as $name => $value) {
            $name = (string) $name;

            if (true === in_array($name, self::SERVER_SET, true)) {
                unset($properties[$name]);

                continue;
            }

            if (false === array_key_exists($name, self::UNSTORED_DEFAULTS)) {
                continue;
            }

            $says = null !== $value && [] !== $value && self::UNSTORED_DEFAULTS[$name] !== $value
                && (false === array_key_exists($name, $published) || $published[$name] !== $value);

            if (false === $says) {
                unset($properties[$name]);
            }
        }

        return $properties;
    }
}
