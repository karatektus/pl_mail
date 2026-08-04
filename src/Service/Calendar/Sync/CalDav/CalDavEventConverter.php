<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\CalDav;

use App\Domain\DTO\Calendar\RemoteEvent;
use App\Domain\Enum\Calendar\EventStatus;
use App\Entity\Calendar\CalendarEvent;
use App\Service\Calendar\RecurrenceRuleConverter;
use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property\ICalendar\DateTime as ICalDateTime;
use Sabre\VObject\Reader;

/**
 * The .ics on one CalDAV resource, and the JSCalendar the engine stores.
 *
 * The driver's whole boundary in both directions. Nothing above this line has
 * ever seen a VEVENT, which is the contract, so every iCalendar concept either
 * becomes a JSCalendar one here or is deliberately left behind — and the ones
 * left behind are named below rather than discovered later by someone
 * wondering where their recurrence went.
 *
 * The mapping follows IcsEventExtractor's conventions exactly, key for key, and
 * that is not laziness: the same meeting arrives twice — once as an invitation
 * in the mailbox and once on the connected calendar — and CalendarPuller
 * matches the two by UID. Two mappings of the same VEVENT that disagreed about
 * `duration` or `showWithoutTime` would present as an event that flickers
 * between two shapes depending on which sync ran last.
 *
 * Two things it inherits from there deliberately:
 *
 *   **Participant roles merge onto the address, never overwrite it.** One
 *   person is routinely ORGANIZER *and* ATTENDEE — it is what Google Calendar
 *   sends and what RFC 5545 expects. Keyed by address and written in property
 *   order, the second line replaced the first, so the only participant carrying
 *   `owner` lost it, the event ended up with no organiser at all, and the
 *   invite card had nobody to answer. That bug was fixed in the extractor; this
 *   is where it would come back.
 *
 *   **RRULE is translated, and only what will not translate is preserved.** An
 *   incoming rule used to be kept verbatim under `plmail:rrule` because
 *   RRULE→JSCalendar did not exist, and RecurrenceMaterialiser reads
 *   recurrenceRules and nothing else — so a weekly meeting on a CalDAV server
 *   appeared here exactly once. It goes through RecurrenceRuleConverter now, in
 *   both directions. A rule that converter refuses still falls back to the
 *   verbatim key, and that key is still written back out unchanged, so a round
 *   trip through plMail cannot silently un-repeat somebody's standing meeting.
 *
 * ── One resource is one series, overrides and all ─────────────────────────
 *
 * A CalDAV resource holds every component sharing a UID: the master, one VEVENT
 * per instance somebody edited, and the master's EXDATEs for the ones they
 * cancelled. All of it becomes the master's `recurrenceOverrides` — a moved
 * instance is a patch, an EXDATE is `{"excluded": true}` — rather than separate
 * RemoteEvents the way Google and Graph have to report them. That is not a
 * different opinion about the model, it is a different fact about the transport:
 * an .ics is atomic, so the map it produces is complete, and the engine can
 * replace what it holds instead of merging into it. An instance moved back at
 * the server is then a resource that no longer mentions it, and it stops being
 * drawn in the wrong place — which a merge could never manage.
 *
 * The same map is written back out as VEVENTs and EXDATEs. Without that, editing
 * the title of a series here would PUT a resource with the master alone and
 * delete every moved instance of it at the server.
 *
 * VALARM, ATTACH and X- properties are not carried either way. They round-trip
 * through neither the columns nor the JSCalendar object today, and writing back
 * an event with its alarms stripped would be worse than not writing it.
 */
final readonly class CalDavEventConverter
{
    /** Identifies plMail in the PRODID of everything it writes. */
    public const string PRODUCT_ID = '-//plMail//Calendar//EN';

    /**
     * An event with neither DTEND nor DURATION is an instant, and a zero-length
     * row is invisible in every view — the same nominal hour IcsEventExtractor
     * gives it, for the same reason.
     */
    private const int DEFAULT_DURATION_MINUTES = 60;

    public function __construct(
        private RecurrenceRuleConverter $recurrence,
    ) {
    }

    /**
     * One calendar resource as the engine sees it, or null when there is
     * nothing usable in it.
     *
     * Null rather than an exception, and the caller logs it: a CalDAV
     * collection legitimately holds VTODOs, VJOURNALs and VFREEBUSYs, and an
     * unreadable resource costs one event where throwing would cost the whole
     * calendar.
     */
    public function toRemoteEvent(string $ics, string $remoteId, ?string $etag): ?RemoteEvent
    {
        try {
            $calendar = Reader::read($ics, Reader::OPTION_FORGIVING);
        } catch (\Throwable) {
            return null;
        }

        if (false === $calendar instanceof VCalendar) {
            return null;
        }

        $vevent = $this->masterEventOf($calendar);

        if (null === $vevent) {
            return null;
        }

        $uid   = trim((string) ($vevent->UID ?? ''));
        $start = $this->instantOf($vevent, 'DTSTART');

        if ('' === $uid || null === $start) {
            // Without a UID there is no identity, and without a start there is
            // nowhere to draw it.
            return null;
        }

        $end      = $this->endOf($vevent, $start);
        $isAllDay = $this->isAllDay($vevent);
        $zone     = $this->zoneOf($vevent);
        $status   = $this->statusOf($vevent, $calendar);

        return new RemoteEvent(
            remoteId:   $remoteId,
            etag:       $etag,
            uid:        $uid,
            isDeleted:  false,
            jscalendar: $this->toJsCalendar($calendar, $vevent, $uid, $start, $end, $zone, $isAllDay, $status),
            startsAt:   $start,
            endsAt:     $end,
        );
    }

    /**
     * The .ics to PUT for one local event.
     *
     * A whole VCALENDAR with one VEVENT, because a CalDAV resource is a
     * calendar object and not a component. No METHOD: that is iTIP's, and a
     * resource stored on a calendar with `METHOD:REQUEST` on it is an
     * invitation some clients will try to deliver.
     */
    public function toIcs(CalendarEvent $event): string
    {
        $calendar = new VCalendar(['PRODID' => self::PRODUCT_ID]);

        // Constructed rather than added by name, because Component::add() hands
        // back a Node and everything below this line needs a component — the
        // same reason ItipReplyBuilder builds its VEVENT this way.
        $vevent = new VEvent($calendar, 'VEVENT', [
            'UID'      => $event->uid,
            'SEQUENCE' => $event->sequence,
            'DTSTAMP'  => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'SUMMARY'  => (string) $event->title,
            'STATUS'   => mb_strtoupper($event->status->value),
        ]);

        $calendar->add($vevent);

        $this->addTimes($vevent, $event);

        $description = $event->jscalendar['description'] ?? null;

        if (true === is_string($description) && '' !== $description) {
            $vevent->add('DESCRIPTION', $description);
        }

        if (null !== $event->location && '' !== $event->location) {
            $vevent->add('LOCATION', $event->location);
        }

        $rrule = $this->rruleOf($event);

        if (null !== $rrule) {
            $vevent->add('RRULE', $rrule);
        }

        $this->addParticipants($vevent, $event);
        $this->addOverrides($calendar, $vevent, $event);

        return $calendar->serialize();
    }

    /**
     * The zone named by a collection's `calendar-timezone`, which is a whole
     * VCALENDAR carrying one VTIMEZONE rather than a zone name.
     *
     * Here rather than in the driver because it is iCalendar parsing, and this
     * is the class that knows what that is. Null for anything unreadable or for
     * a TZID PHP does not recognise — Exchange-flavoured names
     * ("W. Europe Standard Time") land there — and the caller then falls back
     * to the user's own zone, which is a better guess than a wrong zone.
     */
    public function timeZoneOf(string $vtimezone): ?string
    {
        try {
            $calendar = Reader::read($vtimezone, Reader::OPTION_FORGIVING);
        } catch (\Throwable) {
            return null;
        }

        if (false === $calendar instanceof VCalendar) {
            return null;
        }

        $tzid = trim((string) ($calendar->VTIMEZONE->TZID ?? ''));

        if ('' === $tzid) {
            return null;
        }

        try {
            return new DateTimeZone($tzid)->getName();
        } catch (\Exception) {
            return null;
        }
    }

    // ── iCalendar in ──────────────────────────────────────────────────────────

    /**
     * The series master, or the first component when every VEVENT is an
     * override.
     *
     * A CalDAV resource holds every component sharing one UID, so a recurring
     * event arrives as the master plus one VEVENT per edited instance. The
     * master is the one without RECURRENCE-ID, and picking whichever came first
     * would make a series whose second instance was moved report that instance's
     * time as the series' own.
     */
    private function masterEventOf(VCalendar $calendar): ?VEvent
    {
        $first = null;

        foreach ($calendar->VEVENT ?? [] as $vevent) {
            if (false === $vevent instanceof VEvent) {
                continue;
            }

            $first ??= $vevent;

            if (null === ($vevent->{'RECURRENCE-ID'} ?? null)) {
                return $vevent;
            }
        }

        return $first;
    }

    /**
     * @return array<string,mixed>
     */
    private function toJsCalendar(
        VCalendar         $calendar,
        VEvent            $vevent,
        string            $uid,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?string           $zone,
        bool              $isAllDay,
        EventStatus       $status,
    ): array {
        $local = $start->setTimezone(new DateTimeZone($zone ?? 'UTC'));

        $jscalendar = [
            '@type'    => 'Event',
            'uid'      => $uid,
            'title'    => trim((string) ($vevent->SUMMARY ?? '')),
            'start'    => $local->format('Y-m-d\TH:i:s'),
            'duration' => $this->isoDuration($end->getTimestamp() - $start->getTimestamp()),
            'status'   => $status->value,
        ];

        if (null !== $zone && false === $isAllDay) {
            $jscalendar['timeZone'] = $zone;
        }

        if (true === $isAllDay) {
            $jscalendar['showWithoutTime'] = true;
        }

        $description = trim((string) ($vevent->DESCRIPTION ?? ''));

        if ('' !== $description) {
            $jscalendar['description'] = $description;
        }

        $location = trim((string) ($vevent->LOCATION ?? ''));

        if ('' !== $location) {
            $jscalendar['locations'] = ['1' => ['@type' => 'Location', 'name' => $location]];
        }

        $participants = $this->participantsOf($vevent);

        if ([] !== $participants) {
            $jscalendar['participants'] = $participants;
        }

        $rrules = $vevent->select('RRULE');

        if ([] === $rrules) {
            return $jscalendar;
        }

        $verbatim = (string) reset($rrules);
        $rule     = $this->recurrence->fromRrule($verbatim, $zone);

        if (null !== $rule) {
            $jscalendar['recurrenceRules'] = [$rule];
        } else {
            // Only what could not be converted, and kept so rruleOf() can put
            // the server's own rule back rather than dropping the repeat.
            $jscalendar['plmail:rrule'] = $verbatim;
        }

        $overrides = $this->overridesIn(
            $calendar,
            $vevent,
            new DateTimeZone($zone ?? 'UTC'),
            $end->getTimestamp() - $start->getTimestamp(),
        );

        if ([] !== $overrides) {
            $jscalendar['recurrenceOverrides'] = $overrides;
        }

        return $jscalendar;
    }

    /**
     * Every instance of this series the resource says something about.
     *
     * Read only beside an RRULE: a RECURRENCE-ID or an EXDATE without one names
     * an instance of nothing, and filing it would produce a map the expander
     * walks past on an event that does not recur.
     *
     * Keyed and ordered by the instance's ORIGINAL start — the RECURRENCE-ID,
     * never the DTSTART it was moved to. Reading the key off the moved start is
     * the classic mistake here: the expander looks the patch up by where the
     * rule put the instance, so a key taken from where it went matches nothing
     * and the instance is drawn twice, once by the rule and once by nobody.
     *
     * @param DateTimeZone $zone     the series' own, which is the zone the
     *                               expander reads these keys back in
     * @param int          $duration the series' length in seconds, for an
     *                               instance that moved without saying how long
     *                               it now is
     *
     * @return array<string,array<string,mixed>>
     */
    private function overridesIn(VCalendar $calendar, VEvent $master, DateTimeZone $zone, int $duration): array
    {
        $overrides = $this->recurrence->exclusionOverrides($this->exclusionsOf($master), $zone);

        foreach ($calendar->VEVENT ?? [] as $vevent) {
            if (false === $vevent instanceof VEvent) {
                continue;
            }

            $recurrenceId = $this->instantOf($vevent, 'RECURRENCE-ID');

            if (null === $recurrenceId) {
                continue;
            }

            $patch = ['@type' => 'Event'];
            $start = $this->instantOf($vevent, 'DTSTART');

            if (null !== $start) {
                $patch['start']    = $start->setTimezone($zone)->format('Y-m-d\TH:i:s');
                $patch['duration'] = $this->isoDuration($this->endOf($vevent, $start)->getTimestamp() - $start->getTimestamp());
            } else {
                $patch['duration'] = $this->isoDuration($duration);
            }

            $summary = trim((string) ($vevent->SUMMARY ?? ''));

            if ('' !== $summary && $summary !== trim((string) ($master->SUMMARY ?? ''))) {
                $patch['title'] = $summary;
            }

            if (EventStatus::Cancelled === $this->statusOf($vevent, $calendar)) {
                $patch['status'] = EventStatus::Cancelled->value;
            }

            // Last, so a cancelled instance that is also EXDATEd is the patch
            // rather than the exclusion: the row survives, struck through, and
            // "wasn't there something today?" has an answer.
            $overrides[$this->recurrence->overrideKey($recurrenceId, $zone)] = $patch;
        }

        return $overrides;
    }

    /**
     * The instances an EXDATE takes off the series.
     *
     * sabre is asked for the instants rather than the strings because each
     * EXDATE carries its own TZID: an exclusion written in Europe/Berlin and
     * compared against a UTC expansion misses by an hour, which takes the wrong
     * instance off the calendar or none at all.
     *
     * @return list<DateTimeImmutable>
     */
    private function exclusionsOf(VEvent $vevent): array
    {
        $instants = [];

        foreach ($vevent->select('EXDATE') as $exdate) {
            if (false === $exdate instanceof ICalDateTime) {
                continue;
            }

            foreach ($exdate->getDateTimes() as $dateTime) {
                $instants[] = DateTimeImmutable::createFromInterface($dateTime);
            }
        }

        return $instants;
    }

    /**
     * Organiser and attendees, with roles accumulating on the address.
     *
     * See the class docblock: the address is the identity here, and a person
     * who is both organiser and attendee must come out of this with both roles
     * — one participant, `roles` carrying `owner` and `attendee` — not as
     * whichever line the loop read second.
     *
     * @return array<string,array<string,mixed>>
     */
    private function participantsOf(VEvent $vevent): array
    {
        $participants = [];

        foreach (['ORGANIZER' => 'owner', 'ATTENDEE' => 'attendee'] as $property => $role) {
            foreach ($vevent->{$property} ?? [] as $entry) {
                $address = preg_replace('/^mailto:/i', '', trim((string) $entry));

                if (null === $address || '' === $address) {
                    continue;
                }

                $key = mb_strtolower($address);

                $participant = $participants[$key] ?? [
                    '@type' => 'Participant',
                    'email' => $address,
                    'roles' => [],
                ];

                $participant['roles'][$role] = true;

                $name = trim((string) ($entry['CN'] ?? ''));

                if ('' !== $name) {
                    $participant['name'] = $name;
                }

                // An ORGANIZER line carries no PARTSTAT, so the attendee line
                // for the same person is where the organiser's own answer comes
                // from. Written only when the line has one, so a second mention
                // cannot blank an answer already read.
                $partStat = mb_strtolower(trim((string) ($entry['PARTSTAT'] ?? '')));

                if ('' !== $partStat) {
                    $participant['participationStatus'] = $partStat;
                }

                $participants[$key] = $participant;
            }
        }

        return $participants;
    }

    private function statusOf(VEvent $vevent, VCalendar $calendar): EventStatus
    {
        $method = mb_strtoupper(trim((string) ($calendar->METHOD ?? '')));
        $status = mb_strtoupper(trim((string) ($vevent->STATUS ?? '')));

        return match (true) {
            'CANCEL' === $method, 'CANCELLED' === $status => EventStatus::Cancelled,
            'TENTATIVE' === $status                       => EventStatus::Tentative,
            default                                       => EventStatus::Confirmed,
        };
    }

    private function instantOf(VEvent $vevent, string $property): ?DateTimeImmutable
    {
        $value = $vevent->{$property} ?? null;

        if (null === $value) {
            return null;
        }

        try {
            return DateTimeImmutable::createFromInterface($value->getDateTime())
                ->setTimezone(new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function endOf(VEvent $vevent, DateTimeImmutable $start): DateTimeImmutable
    {
        $end = $this->instantOf($vevent, 'DTEND');

        if (null !== $end && $end > $start) {
            return $end;
        }

        $durations = $vevent->select('DURATION');

        if ([] !== $durations) {
            try {
                return $start->add(DateTimeParser::parseDuration((string) reset($durations)));
            } catch (\Throwable) {
                // Falls through to the nominal hour: an unparseable DURATION is
                // no more informative than an absent one.
            }
        }

        return $start->modify(sprintf('+%d minutes', self::DEFAULT_DURATION_MINUTES));
    }

    /** A DATE value rather than a DATE-TIME is what makes an event all-day. */
    private function isAllDay(VEvent $vevent): bool
    {
        $start = $vevent->DTSTART ?? null;

        return null !== $start && 'DATE' === mb_strtoupper((string) ($start['VALUE'] ?? ''));
    }

    private function zoneOf(VEvent $vevent): ?string
    {
        $tzid = trim((string) ($vevent->DTSTART['TZID'] ?? ''));

        if ('' === $tzid) {
            return null;
        }

        try {
            return new DateTimeZone($tzid)->getName();
        } catch (\Exception) {
            // Windows zone names ("W. Europe Standard Time") and outright
            // invalid ones both land here. Null means floating, which is the
            // honest answer when we cannot say which zone was meant.
            return null;
        }
    }

    // ── iCalendar out ─────────────────────────────────────────────────────────

    /**
     * DTSTART and DTEND, in the shape the event's own zone calls for.
     *
     * Three cases and they are not interchangeable: an all-day event is a DATE
     * (a floating day, which is what makes a birthday the same day everywhere),
     * a zoned event carries TZID so the server and every other client can
     * re-render it after a DST change, and a zoneless one is written as UTC.
     *
     * The all-day pair is formatted by hand rather than handed to the library
     * as a DateTime, exactly as ItipReplyBuilder does and for the same bug: a
     * date-time where a DATE was meant is shifted by the reader's offset, which
     * is how a birthday arrives on the wrong day.
     */
    private function addTimes(VEvent $vevent, CalendarEvent $event): void
    {
        $start = $event->startsAt;
        $end   = $event->endsAt;

        if (null === $start || null === $end) {
            return;
        }

        if (true === $event->isAllDay) {
            $vevent->add('DTSTART', $start->format('Ymd'), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $end->format('Ymd'), ['VALUE' => 'DATE']);

            return;
        }

        $zone = $event->timeZone;

        if (null === $zone) {
            $vevent->add('DTSTART', $start->setTimezone(new DateTimeZone('UTC')));
            $vevent->add('DTEND', $end->setTimezone(new DateTimeZone('UTC')));

            return;
        }

        try {
            $timeZone = new DateTimeZone($zone);
        } catch (\Exception) {
            $timeZone = new DateTimeZone('UTC');
        }

        $vevent->add('DTSTART', $start->setTimezone($timeZone));
        $vevent->add('DTEND', $end->setTimezone($timeZone));
    }

    /**
     * The RRULE to write, local rule first.
     *
     * The verbatim `plmail:rrule` is the fallback rather than the first choice:
     * it is what came off the server, so preferring it would mean an event
     * whose repeat a user changed in plMail is pushed back with its old rule.
     */
    private function rruleOf(CalendarEvent $event): ?string
    {
        $rules = $event->jscalendar['recurrenceRules'] ?? null;

        if (true === is_array($rules) && true === is_array($rules[0] ?? null)) {
            $converted = $this->recurrence->toRrule($rules[0]);

            if (null !== $converted) {
                return $converted;
            }
        }

        $verbatim = $event->jscalendar['plmail:rrule'] ?? null;

        return true === is_string($verbatim) && '' !== $verbatim ? $verbatim : null;
    }

    /**
     * The series' recurrenceOverrides, as the components a CalDAV resource says
     * them with.
     *
     * Two shapes, because iCalendar has two: an excluded instance is an EXDATE
     * on the master, and everything else is a VEVENT of its own carrying the
     * same UID and a RECURRENCE-ID naming the instance it replaces. Both are
     * written from the same map, so a resource read and written back is the
     * resource that arrived.
     *
     * The alternative — writing the master alone — is what this file did before
     * overrides were read at all, and it was harmless only because there were
     * none to lose. Now there are: a PUT replaces the whole resource, so a
     * missing override VEVENT deletes the moved instance at the server, and the
     * user who corrected a typo in the title finds every occurrence back on its
     * original day.
     */
    private function addOverrides(VCalendar $calendar, VEvent $master, CalendarEvent $event): void
    {
        $overrides = $event->jscalendar['recurrenceOverrides'] ?? null;
        $startsAt  = $event->startsAt;
        $endsAt    = $event->endsAt;

        if (false === is_array($overrides) || null === $startsAt || null === $endsAt) {
            return;
        }

        $zone     = $this->zoneOfEvent($event);
        $duration = $endsAt->getTimestamp() - $startsAt->getTimestamp();
        $excluded = [];

        foreach ($overrides as $key => $patch) {
            if (false === is_string($key) || false === is_array($patch)) {
                continue;
            }

            $recurrenceId = $this->localInstant($key, $zone);

            if (null === $recurrenceId) {
                continue;
            }

            if (true === ($patch['excluded'] ?? false)) {
                $excluded[] = $recurrenceId->setTimezone($zone);

                continue;
            }

            $start = $this->localInstant(
                true === is_string($patch['start'] ?? null) ? $patch['start'] : $key,
                $zone,
            ) ?? $recurrenceId;

            $instance = new VEvent($calendar, 'VEVENT', [
                'UID'      => $event->uid,
                'SEQUENCE' => $event->sequence,
                'DTSTAMP'  => new DateTimeImmutable('now', new DateTimeZone('UTC')),
                'SUMMARY'  => true === is_string($patch['title'] ?? null) ? $patch['title'] : (string) $event->title,
                'STATUS'   => mb_strtoupper(
                    true === is_string($patch['status'] ?? null) ? $patch['status'] : $event->status->value,
                ),
            ]);

            $calendar->add($instance);

            $instance->add('RECURRENCE-ID', $recurrenceId->setTimezone($zone));
            $instance->add('DTSTART', $start->setTimezone($zone));
            $instance->add('DTEND', $this->instanceEnd($start, $patch, $duration)->setTimezone($zone));
        }

        if ([] !== $excluded) {
            $master->add('EXDATE', $excluded);
        }
    }

    /**
     * An instance's end: its own duration when the patch states one, and the
     * series' length when it does not.
     *
     * @param array<string,mixed> $patch
     */
    private function instanceEnd(DateTimeImmutable $start, array $patch, int $duration): DateTimeImmutable
    {
        if (true === is_string($patch['duration'] ?? null)) {
            try {
                return $start->add(new \DateInterval($patch['duration']));
            } catch (\Exception) {
                // An unreadable duration is no more informative than an absent
                // one, and the series' length is the honest fallback.
            }
        }

        return $start->modify(sprintf('+%d seconds', $duration));
    }

    /**
     * A JSCalendar LocalDateTime as the instant it names in this zone.
     *
     * Null for anything that is not one, so a hand-edited object cannot put a
     * RECURRENCE-ID of "tomorrow" on the wire.
     */
    private function localInstant(string $local, DateTimeZone $zone): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $local, $zone);

        return false === $parsed ? null : $parsed;
    }

    private function zoneOfEvent(CalendarEvent $event): DateTimeZone
    {
        if (null === $event->timeZone || '' === $event->timeZone) {
            return new DateTimeZone('UTC');
        }

        try {
            return new DateTimeZone($event->timeZone);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * ORGANIZER and ATTENDEE lines, back out of the merged participants.
     *
     * One participant can produce both lines, which is the reverse of the merge
     * on the way in and the reason a round trip does not quietly demote the
     * organiser to an ordinary invitee.
     */
    private function addParticipants(VEvent $vevent, CalendarEvent $event): void
    {
        $participants = $event->jscalendar['participants'] ?? null;

        if (false === is_array($participants)) {
            return;
        }

        foreach ($participants as $participant) {
            if (false === is_array($participant)) {
                continue;
            }

            $email = $participant['email'] ?? null;

            if (false === is_string($email) || '' === $email) {
                continue;
            }

            $roles      = true === is_array($participant['roles'] ?? null) ? $participant['roles'] : [];
            $parameters = [];
            $name       = $participant['name'] ?? null;

            if (true === is_string($name) && '' !== $name) {
                $parameters['CN'] = $name;
            }

            if (true === ($roles['owner'] ?? false)) {
                $vevent->add('ORGANIZER', 'mailto:' . $email, $parameters);
            }

            if (true === ($roles['attendee'] ?? false)) {
                $partStat = $participant['participationStatus'] ?? null;

                if (true === is_string($partStat) && '' !== $partStat) {
                    $parameters['PARTSTAT'] = mb_strtoupper($partStat);
                }

                $vevent->add('ATTENDEE', 'mailto:' . $email, $parameters);
            }
        }
    }

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

        $time = (0 < $hours ? $hours . 'H' : '')
            . (0 < $minutes ? $minutes . 'M' : '')
            . (0 < $rest ? $rest . 'S' : '');

        return 'P' . (0 < $days ? $days . 'D' : '') . ('' === $time ? '' : 'T' . $time);
    }
}
