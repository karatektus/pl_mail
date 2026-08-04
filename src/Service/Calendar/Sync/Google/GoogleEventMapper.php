<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\Google;

use App\Domain\DTO\Calendar\RemoteEvent;
use App\Domain\Enum\Calendar\EventPrivacy;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Entity\Calendar\CalendarEvent;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Google's `Event` resource and RFC 8984 JSCalendar, in both directions.
 *
 * Its own class because it is the substance of the driver and it is the part
 * with no I/O in it: everything here is a pure function of one decoded resource
 * or one local row, which is what makes the awkward cases — an all-day event, a
 * cancellation, an organiser who is also an attendee — testable without a
 * request. GoogleCalendarSyncDriver is then about paging and contracts, and
 * reads in one screen.
 *
 * The decisions that are not obvious from the code:
 *
 *   **A cancelled event is a tombstone, not a status.** plMail has
 *   EventStatus::Cancelled and shows such events struck through, because "was
 *   this called off or did I imagine it?" deserves an answer. Google's
 *   `status: "cancelled"` in a delta window is not that: it is how the API says
 *   an event was removed, and it arrives with no summary, no times and no
 *   attendees. Mapping it to a cancelled *event* would leave a row with no
 *   title and no time on the calendar forever, so it maps to
 *   RemoteEvent::deleted() as the interface intends.
 *
 *   **An instance of a series is an override, not an event.** Google returns a
 *   moved or cancelled occurrence as its own resource carrying
 *   `recurringEventId` and `originalStartTime`, and those two become
 *   RemoteEvent::$seriesRemoteId and $recurrenceId so the engine files it onto
 *   the series. Mapped as an ordinary event — which is what happened until this
 *   was written — the moved instance became a second local row: a duplicate on
 *   the day it was moved to, beside a series still drawing it on the day it
 *   left. A cancelled instance was worse, because it mapped to a tombstone that
 *   matched no row and did nothing at all, so the occurrence somebody had called
 *   off stayed on the calendar.
 *
 *   **Identity is `id`; the UID is `iCalUID`.** They are different strings at
 *   Google and both are needed — the id addresses the resource, the iCalUID is
 *   what the invitation sitting in the mailbox has in common with the meeting
 *   on the calendar, and it is how the engine avoids writing the same meeting
 *   twice. An event with no iCalUID falls back to the id rather than being
 *   skipped.
 *
 *   **A timed event with no `start.timeZone` takes the calendar's zone.**
 *   Google returns an explicit zone on recurring events and often omits it on
 *   single ones, where the offset in `dateTime` already fixes the instant. The
 *   instant is never in doubt; only which wall clock to display it against is,
 *   and the calendar's own zone is the one Google's UI would use.
 *
 *   **All-day events are floating.** `start.date` becomes local midnight with
 *   no zone, and `end.date` stays exclusive, which is what both iCalendar and
 *   CalendarEvent already mean by it. Converting an all-day event into a
 *   midnight-to-midnight timed one in some zone is how a birthday ends up on
 *   the wrong day for everybody an hour to the west.
 *
 *   **A person can be organiser and attendee at once, and the roles merge.**
 *   Google sends the organiser twice for a meeting its owner is also going to —
 *   once as `organizer`, once in `attendees` — and the second mention carries
 *   the RSVP the first does not. Keyed by address with roles accumulating,
 *   exactly as IcsEventExtractor::participantsOf() had to be fixed to do: there,
 *   writing the second over the first left the event with no participant
 *   carrying `owner`, so the invite card had nobody to answer for and offered
 *   no answer at all.
 *
 * What Google says that JSCalendar has no home for, and is therefore dropped:
 * `colorId` (plMail colours calendars, not events), `reminders`
 * (JSCalendar alerts exist, but plMail has no alert feature to honour them
 * with, and writing them back is how a user gets notified twice),
 * `conferenceData`, `attachments`, `guestsCanModify` and friends, and
 * `organizer.self`/`attendees[].self` — which is real information, but plMail
 * answers "is this me?" by comparing addresses to the account's own and does
 * not need Google's opinion recorded. Because updates are sent as PATCH, none
 * of these are erased by a push that does not mention them.
 */
final readonly class GoogleEventMapper
{
    /**
     * How long an event lasts when Google will not say.
     *
     * `endTimeUnspecified` events, and the malformed resource with no `end` at
     * all, would otherwise be zero-length — and a zero-length row is invisible
     * in every view, which presents as "the event synced but is not on the
     * calendar". An hour is the same nominal length IcsEventExtractor gives a
     * VEVENT with neither an end nor a duration, for the same reason.
     */
    private const int DEFAULT_DURATION_MINUTES = 60;

    /** Google's `status`, which is also JSCalendar's, minus the one that means "gone". */
    private const string CANCELLED = 'cancelled';

    /** Google's attendee `responseStatus` to JSCalendar participationStatus (RFC 8984 §4.4.6). */
    private const array PARTICIPATION_STATUS = [
        'needsAction' => 'needs-action',
        'declined'    => 'declined',
        'tentative'   => 'tentative',
        'accepted'    => 'accepted',
    ];

    /**
     * Google's `visibility` to JSCalendar privacy.
     *
     * "default" is absent on purpose: it means "whatever this calendar does",
     * which is a statement about the calendar and not about the event, so it
     * leaves privacy unmentioned and CalendarEvent keeps its own.
     * "confidential" collapses onto private — iCalendar distinguishes them,
     * plMail's EventPrivacy does not, and inventing Secret for it would hide
     * the event from a share the user never asked to hide it from.
     */
    private const array PRIVACY = [
        'public'       => 'public',
        'private'      => 'private',
        'confidential' => 'private',
    ];

    public function __construct(
        private GoogleRecurrenceMapper $recurrence,
    ) {
    }

    /**
     * One item from `events.list` as the engine's own vocabulary, or null when
     * the resource is unusable.
     *
     * Null rather than an exception for a resource with no id or no start: one
     * malformed event out of two hundred must not cost the window the other
     * hundred and ninety-nine are in.
     *
     * @param array<string,mixed> $item             one Google `Event` resource
     * @param string              $fallbackTimeZone the calendar's own zone, for
     *                                              the timed events Google
     *                                              describes with an offset and
     *                                              no zone name
     */
    public function toRemoteEvent(array $item, string $fallbackTimeZone): ?RemoteEvent
    {
        $remoteId = $this->stringOrNull($item['id'] ?? null);

        if (null === $remoteId) {
            return null;
        }

        $uid  = $this->stringOrNull($item['iCalUID'] ?? null) ?? $remoteId;
        $etag = $this->stringOrNull($item['etag'] ?? null);

        $seriesRemoteId = $this->stringOrNull($item['recurringEventId'] ?? null);
        $recurrenceId   = null === $seriesRemoteId ? null : $this->originalStart($item);

        if (self::CANCELLED === $this->stringOrNull($item['status'] ?? null)) {
            // An instance somebody removed from a series is not a deletion: the
            // series is alive and the row must stay, with that one occurrence
            // excluded. Reported as a plain tombstone it matches no row at all —
            // an instance has never been one — and the cancelled occurrence goes
            // on being drawn for good.
            if (null !== $seriesRemoteId && null !== $recurrenceId) {
                return RemoteEvent::deletedInstance($remoteId, $seriesRemoteId, $recurrenceId);
            }

            return RemoteEvent::deleted($remoteId, $uid);
        }

        $times = $this->timesOf($item, $fallbackTimeZone);

        if (null === $times) {
            return null;
        }

        return new RemoteEvent(
            remoteId:       $remoteId,
            etag:           $etag,
            uid:            $uid,
            isDeleted:      false,
            jscalendar:     $this->toJsCalendar($item, $uid, $times),
            startsAt:       $times['startsAt'],
            endsAt:         $times['endsAt'],
            // Only when both are known. A moved instance whose originalStartTime
            // Google did not state has no key to be filed under, and a patch with
            // no key is a patch on nothing — better the duplicate row that was
            // there before than an instance that quietly vanishes.
            seriesRemoteId: null === $recurrenceId ? null : $seriesRemoteId,
            recurrenceId:   $recurrenceId,
        );
    }

    /**
     * A local row as the body of an events.insert or events.patch.
     *
     * Every field plMail models is sent on every write, including the ones that
     * are empty — a PATCH leaves out what it does not mention, so omitting an
     * emptied description would make "clear the description" a change that
     * silently does not travel. `attendees` is the one exception and it is
     * deliberate: the local editor cannot express a guest list, so an event
     * that has none locally means "plMail has nothing to say about the guests",
     * not "remove them". Sending an empty array there would uninvite everyone
     * the moment somebody fixed a typo in the title.
     *
     * @return array<string,mixed>
     *
     * @throws CalendarSyncPermanentException when the row cannot be expressed
     *                                        as a Google event at all
     */
    public function toGoogleEvent(CalendarEvent $event): array
    {
        $startsAt = $event->startsAt;
        $endsAt   = $event->endsAt;

        if (null === $startsAt || null === $endsAt) {
            // Permanent, so CalendarPusher retires this one event and lets the
            // rest of the batch go out. A row with no times is a local defect
            // and no number of retries will grow it one.
            throw new CalendarSyncPermanentException(sprintf(
                'Event "%s" has no start or end, so it cannot be sent to Google.',
                $event->uid,
            ));
        }

        $jscalendar = $event->jscalendar;

        $payload = [
            'summary'     => (string) $event->title,
            'description' => $this->stringOrNull($jscalendar['description'] ?? null),
            'location'    => $event->location,
            'status'      => $event->status->value,
            'start'       => $this->googleTime($event, $startsAt),
            'end'         => $this->googleTime($event, $endsAt),
            'visibility'  => $this->googleVisibility($event->privacy),
            // Null clears the recurrence, which is what a series demoted to a
            // single event here has to mean there.
            'recurrence'  => $this->recurrence->toGoogleRecurrence($jscalendar),
        ];

        $attendees = $this->googleAttendees($jscalendar);

        if ([] !== $attendees) {
            $payload['attendees'] = $attendees;
        }

        return $payload;
    }

    /**
     * @param array<string,mixed>                                                              $item
     * @param array{startsAt: DateTimeImmutable, endsAt: DateTimeImmutable, timeZone: ?string, isAllDay: bool} $times
     *
     * @return array<string,mixed>
     */
    private function toJsCalendar(array $item, string $uid, array $times): array
    {
        $zone  = $times['timeZone'];
        $local = $times['startsAt']->setTimezone(new DateTimeZone($zone ?? 'UTC'));

        // JSCalendar times are LocalDateTime — no offset, no trailing Z — with
        // timeZone naming the zone they are local to (RFC 8984 §4.1.2).
        $jscalendar = [
            '@type'    => 'Event',
            'uid'      => $uid,
            'title'    => (string) ($this->stringOrNull($item['summary'] ?? null) ?? ''),
            'start'    => $local->format('Y-m-d\TH:i:s'),
            'duration' => $this->isoDuration($times['endsAt']->getTimestamp() - $times['startsAt']->getTimestamp()),
            'status'   => $this->stringOrNull($item['status'] ?? null) ?? 'confirmed',
        ];

        if (null !== $zone) {
            $jscalendar['timeZone'] = $zone;
        }

        if (true === $times['isAllDay']) {
            $jscalendar['showWithoutTime'] = true;
        }

        $description = $this->stringOrNull($item['description'] ?? null);

        if (null !== $description) {
            $jscalendar['description'] = $description;
        }

        $location = $this->stringOrNull($item['location'] ?? null);

        if (null !== $location) {
            $jscalendar['locations'] = ['1' => ['@type' => 'Location', 'name' => $location]];
        }

        $privacy = self::PRIVACY[(string) $this->stringOrNull($item['visibility'] ?? null)] ?? null;

        if (null !== $privacy) {
            $jscalendar['privacy'] = $privacy;
        }

        $sequence = $item['sequence'] ?? null;

        if (true === is_int($sequence)) {
            $jscalendar['sequence'] = $sequence;
        }

        $participants = $this->participantsOf($item);

        if ([] !== $participants) {
            $jscalendar['participants'] = $participants;
        }

        $recurrence = $item['recurrence'] ?? null;

        if (true === is_array($recurrence)) {
            $rule = $this->recurrence->toJsCalendarRule(array_values($recurrence), $zone);

            if (null !== $rule) {
                $jscalendar['recurrenceRules'] = [$rule];
            }

            $preserved = $this->recurrence->preservedLines(array_values($recurrence));

            if ([] !== $preserved) {
                $jscalendar[GoogleRecurrenceMapper::PRESERVED_LINES] = $preserved;
            }
        }

        return $jscalendar;
    }

    /**
     * Organiser and attendees, with their answer where they gave one.
     *
     * Roles are MERGED onto whoever already holds the address, never assigned
     * over the top of them — see the class docblock for the bug that is. The
     * address is the identity; the roles accumulate.
     *
     * @param array<string,mixed> $item
     *
     * @return array<string,array<string,mixed>>
     */
    private function participantsOf(array $item): array
    {
        $organizer = $item['organizer'] ?? null;
        $attendees = $item['attendees'] ?? null;

        $byRole = [
            'owner'    => true === is_array($organizer) ? [$organizer] : [],
            'attendee' => true === is_array($attendees) ? $attendees : [],
        ];

        $participants = [];

        foreach ($byRole as $role => $entries) {
            foreach ($entries as $entry) {
                if (false === is_array($entry)) {
                    continue;
                }

                $address = $this->stringOrNull($entry['email'] ?? null);

                if (null === $address) {
                    continue;
                }

                $key         = mb_strtolower($address);
                $participant = $participants[$key] ?? [
                    '@type' => 'Participant',
                    'email' => $address,
                    'roles' => [],
                ];

                $participant['roles'][$role] = true;

                if (true === ($entry['optional'] ?? false)) {
                    $participant['roles']['optional'] = true;
                }

                // A room, a projector, a company car. JSCalendar says these are
                // participants of a different kind rather than people, and an
                // RSVP view that lists a meeting room among the guests is one
                // nobody trusts.
                if (true === ($entry['resource'] ?? false)) {
                    $participant['kind'] = 'resource';
                }

                $name = $this->stringOrNull($entry['displayName'] ?? null);

                if (null !== $name) {
                    $participant['name'] = $name;
                }

                // The `organizer` object carries no responseStatus, so the
                // organiser's own answer comes from their attendee line. Only
                // written when the entry actually has one, so a second mention
                // can never blank an answer already read.
                $status = self::PARTICIPATION_STATUS[(string) $this->stringOrNull($entry['responseStatus'] ?? null)] ?? null;

                if (null !== $status) {
                    $participant['participationStatus'] = $status;
                }

                $participants[$key] = $participant;
            }
        }

        return $participants;
    }

    /**
     * The instants and the zone, or null when the resource describes no time at
     * all.
     *
     * @param array<string,mixed> $item
     *
     * @return array{startsAt: DateTimeImmutable, endsAt: DateTimeImmutable, timeZone: ?string, isAllDay: bool}|null
     */
    private function timesOf(array $item, string $fallbackTimeZone): ?array
    {
        $start = true === is_array($item['start'] ?? null) ? $item['start'] : [];
        $end   = true === is_array($item['end'] ?? null) ? $item['end'] : [];

        $startDate = $this->stringOrNull($start['date'] ?? null);

        if (null !== $startDate) {
            $startsAt = $this->midnight($startDate);

            if (null === $startsAt) {
                return null;
            }

            // end.date is exclusive at Google, in iCalendar and in
            // CalendarEvent, so it is carried across rather than adjusted. An
            // end that is missing or not after the start gets one day, which is
            // what a single-day all-day event is.
            $endsAt = $this->midnight($this->stringOrNull($end['date'] ?? null) ?? '');

            if (null === $endsAt || $endsAt <= $startsAt) {
                $endsAt = $startsAt->modify('+1 day');
            }

            return [
                'startsAt' => $startsAt,
                'endsAt'   => $endsAt,
                'timeZone' => null,
                'isAllDay' => true,
            ];
        }

        $startsAt = $this->instant($this->stringOrNull($start['dateTime'] ?? null));

        if (null === $startsAt) {
            return null;
        }

        $endsAt = $this->instant($this->stringOrNull($end['dateTime'] ?? null));

        if (null === $endsAt || true === ($item['endTimeUnspecified'] ?? false) || $endsAt < $startsAt) {
            $endsAt = $startsAt->modify(sprintf('+%d minutes', self::DEFAULT_DURATION_MINUTES));
        }

        $zone = $this->zoneNameOrNull($this->stringOrNull($start['timeZone'] ?? null))
            ?? $this->zoneNameOrNull($fallbackTimeZone)
            ?? 'UTC';

        return [
            'startsAt' => $startsAt,
            'endsAt'   => $endsAt,
            'timeZone' => $zone,
            'isAllDay' => false,
        ];
    }

    /**
     * Where the rule originally put an instance somebody has moved.
     *
     * `originalStartTime` has the same shape as `start` — a dateTime with an
     * offset, or a date for an all-day series — and it is the instance's
     * identity: the one thing that does not change when it is dragged to another
     * day, and therefore the only thing a later update can be matched on.
     *
     * @param array<string,mixed> $item
     */
    private function originalStart(array $item): ?DateTimeImmutable
    {
        $original = $item['originalStartTime'] ?? null;

        if (false === is_array($original)) {
            return null;
        }

        $date = $this->stringOrNull($original['date'] ?? null);

        if (null !== $date) {
            return $this->midnight($date);
        }

        return $this->instant($this->stringOrNull($original['dateTime'] ?? null));
    }

    /**
     * An all-day boundary as the UTC instant CalendarEvent stores: local
     * midnight, floating, which the column holds as midnight UTC.
     */
    private function midnight(string $date): ?DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        try {
            return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * The instant behind an RFC 3339 dateTime.
     *
     * The offset in the string is authoritative — it is what fixes the moment —
     * and the result is normalised to UTC because that is what crosses the
     * driver boundary and what the columns are.
     */
    private function instant(?string $dateTime): ?DateTimeImmutable
    {
        if (null === $dateTime) {
            return null;
        }

        try {
            return new DateTimeImmutable($dateTime)->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Google's zone names are IANA, but the resource is decoded JSON and a
     * calendar's own timeZone column is a plain string — one install with a
     * hand-edited row is all it takes for new DateTimeZone() to throw inside a
     * sweep and take every calendar after it with it.
     */
    private function zoneNameOrNull(?string $timeZone): ?string
    {
        if (null === $timeZone) {
            return null;
        }

        try {
            new DateTimeZone($timeZone);
        } catch (\Exception) {
            return null;
        }

        return $timeZone;
    }

    /**
     * @return array{date: string}|array{dateTime: string, timeZone: string}
     */
    private function googleTime(CalendarEvent $event, DateTimeImmutable $instant): array
    {
        if (true === $event->isAllDay) {
            return ['date' => $instant->format('Y-m-d')];
        }

        $zone = $this->zoneNameOrNull($event->timeZone) ?? 'UTC';

        // Both, although the offset alone would fix the instant: the zone name
        // is what makes a recurring event move with its city rather than stay
        // on an offset that stops being right in October.
        return [
            'dateTime' => $instant->setTimezone(new DateTimeZone($zone))->format(DateTimeInterface::RFC3339),
            'timeZone' => $zone,
        ];
    }

    /**
     * Secret collapses onto private, because Google has no third level. It is
     * the safe direction: private is the more restrictive of the two Google
     * offers, and an event nobody outside the account should see is at least
     * not described to anyone the calendar is shared with.
     */
    private function googleVisibility(EventPrivacy $privacy): string
    {
        return match ($privacy) {
            EventPrivacy::Public  => 'default',
            EventPrivacy::Private => 'private',
            EventPrivacy::Secret  => 'private',
        };
    }

    /**
     * @param array<string,mixed> $jscalendar
     *
     * @return list<array<string,mixed>>
     */
    private function googleAttendees(array $jscalendar): array
    {
        $participants = $jscalendar['participants'] ?? null;

        if (false === is_array($participants)) {
            return [];
        }

        $answers   = array_flip(self::PARTICIPATION_STATUS);
        $attendees = [];

        foreach ($participants as $participant) {
            if (false === is_array($participant)) {
                continue;
            }

            $address = $this->stringOrNull($participant['email'] ?? null);

            if (null === $address) {
                continue;
            }

            $roles = true === is_array($participant['roles'] ?? null) ? $participant['roles'] : [];

            // Someone who is only the owner is the organiser, and Google does
            // not accept an organiser through this field — sending them as an
            // attendee would invite the person whose meeting it is to their own
            // meeting, and mail them about it.
            if (true !== ($roles['attendee'] ?? false)) {
                continue;
            }

            $attendee = ['email' => $address];

            $name = $this->stringOrNull($participant['name'] ?? null);

            if (null !== $name) {
                $attendee['displayName'] = $name;
            }

            if (true === ($roles['optional'] ?? false)) {
                $attendee['optional'] = true;
            }

            if ('resource' === $this->stringOrNull($participant['kind'] ?? null)) {
                $attendee['resource'] = true;
            }

            $answer = $answers[(string) $this->stringOrNull($participant['participationStatus'] ?? null)] ?? null;

            if (null !== $answer) {
                $attendee['responseStatus'] = $answer;
            }

            $attendees[] = $attendee;
        }

        return $attendees;
    }

    /**
     * ISO 8601 duration, which is how JSCalendar says how long something is.
     *
     * The same shape CalendarEventWriter derives from the columns, which is not
     * duplication that can drift: the writer recomputes it from the instants
     * this object arrives with, so the two are two expressions of one
     * subtraction and they agree by construction. Kept here because the
     * canonical object is built before anything has been written.
     */
    private function isoDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);

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
     * A decoded Google resource can hold anything in any key — a mapping that
     * casts without asking is one hostile response away from a TypeError that
     * fails the whole sweep.
     */
    private function stringOrNull(mixed $value): ?string
    {
        if (false === is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
