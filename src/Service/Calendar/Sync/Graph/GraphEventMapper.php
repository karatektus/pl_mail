<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\Graph;

use App\Domain\DTO\Calendar\InstanceOverride;
use App\Domain\DTO\Calendar\RemoteEvent;
use App\Domain\Enum\Calendar\AlertAction;
use App\Domain\Enum\Calendar\EventPrivacy;
use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Entity\Calendar\CalendarEvent;
use App\Service\Calendar\Alert\AlertReader;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Microsoft Graph's `event` resource and RFC 8984 JSCalendar, in both
 * directions.
 *
 * Its own class, and not folded into GraphCalendarSyncDriver, for the reason
 * every mapping in this codebase gets one: this is where the judgement is, and
 * the judgement is testable without a socket. The driver decides which URL to
 * call and what a status code means; this decides what Graph's `showAs: oof`
 * becomes when the only two values JSCalendar has are free and busy. Reading
 * those two decisions in one four-hundred-line file is how the second one stops
 * being reviewed.
 *
 * ── The four things Graph does that JSCalendar has no home for ─────────────
 *
 *   **showAs beyond free/busy.** RFC 8984 §4.4.5 defines exactly "free" and
 *   "busy"; Graph adds tentative, oof and workingElsewhere. Everything that is
 *   not free maps to busy, and the original is kept verbatim under
 *   `plmail:graphShowAs`, so a pull followed by a push does not quietly demote
 *   somebody's out-of-office to an ordinary meeting. The prefixed key follows
 *   IcsEventExtractor's `plmail:rrule`.
 *
 *   **isCancelled is read-only.** A cancelled Graph event maps in as
 *   `status: cancelled`, which is what EventStatus stores and what the strike
 *   through in the UI reads. It cannot map back out: Graph refuses the property
 *   on a write and cancels a meeting through its own /cancel action, which
 *   mails every attendee. Cancelling from plMail is therefore a delete, and
 *   `status` is simply not sent.
 *
 *   **A different organizer cannot be asserted.** Graph makes the mailbox owner
 *   the organizer of anything created in their calendar and rejects any other.
 *   The `owner` role therefore reads in and does not write out.
 *
 *   **An online meeting cannot be created from a URL.** `onlineMeeting.joinUrl`
 *   reads in as a JSCalendar virtualLocation, but Graph only mints one from
 *   `isOnlineMeeting` plus a provider — handing it back a URL creates nothing
 *   and drops it. So virtualLocations do not write out, and an event that gained
 *   a Teams link at the remote keeps it there.
 *
 *   **A Graph event has exactly one reminder, and JSCalendar has a map.**
 *   `isReminderOn` plus `reminderMinutesBeforeStart` is the whole of Graph's
 *   model: one popup, N minutes before the start, no email option and no way to
 *   express a second one. It maps in as a single display alert and out as
 *   whichever local alert fires LAST — the one nearest the start, because that
 *   is the one that means "now" and it is the one an Outlook user would expect
 *   to see. The others stay here and fire here; nothing is lost locally, and an
 *   event with a day's warning and a ten-minute warning gets the ten-minute one
 *   in Outlook.
 *
 *   Unlike Google's `reminders`, this one CAN be cleared: `isReminderOn: false`
 *   is unambiguous where "no overrides" is not, so removing every alert here
 *   removes the reminder there. An email alert has no counterpart at all and
 *   does not turn the reminder on — Graph would show a popup for something the
 *   user asked to be mailed.
 *
 * Deliberately not carried at all: `importance`, `hasAttachments`, `webLink`,
 * `bodyPreview`, `seriesMasterId`. The first has a JSCalendar counterpart
 * (priority) but no local column or UI to read it, and a field that round-trips
 * through storage without ever being shown is a field nobody notices has broken;
 * the rest are Graph's own bookkeeping.
 *
 * ── Times ─────────────────────────────────────────────────────────────────
 *
 * Instants are read from Graph's own values, which are UTC unless the request
 * asked otherwise, so a start time never depends on the Windows-to-IANA table
 * resolving. The zone name does: it is taken from `originalStartTimeZone`,
 * which is where Graph keeps the zone the organiser actually chose, and falls
 * back to the zone on `start`. That distinction matters for exactly one thing
 * and it is the important one — a weekly 09:00 Berlin standup expands at 09:00
 * Berlin on both sides of the March change only if the rule is expanded in
 * Europe/Berlin, and `start.timeZone` says UTC.
 */
final readonly class GraphEventMapper
{
    /**
     * Graph's attendee response as a JSCalendar participationStatus.
     *
     * `none` is absent on purpose rather than mapped to needs-action: it is what
     * Graph puts on the organiser's own line, which carries no answer at all,
     * and writing a status from it would overwrite the real answer the same
     * person's attendee line gives. `organizer` is accepted, because an
     * organiser is at their own meeting.
     *
     * @var array<string,string>
     */
    private const array RESPONSES = [
        'organizer'           => 'accepted',
        'accepted'            => 'accepted',
        'declined'            => 'declined',
        'tentativelyaccepted' => 'tentative',
        'notresponded'        => 'needs-action',
    ];

    /**
     * Graph's sensitivity as JSCalendar privacy (RFC 8984 §4.4.3).
     *
     * `personal` and `private` both land on private. Outlook's "personal" means
     * "mine, not the company's", which has no counterpart in a model with three
     * levels, and the honest reading of it is the more restrictive of the two it
     * sits between.
     *
     * @var array<string,string>
     */
    private const array SENSITIVITIES = [
        'normal'       => 'public',
        'personal'     => 'private',
        'private'      => 'private',
        'confidential' => 'secret',
    ];

    public function __construct(
        private GraphTimeZoneMapper   $zones,
        private GraphRecurrenceMapper $recurrence,
        private AlertReader           $alerts,
    ) {
    }

    /**
     * One Graph event resource as a RemoteEvent, or null when it is not usable.
     *
     * Null rather than an exception for a resource with no id or no start:
     * CalendarPuller already refuses an incomplete event and says whose bug it
     * is, and failing the whole window over one malformed resource would stop a
     * calendar syncing at all because of a single event nobody can find.
     *
     * @param array<string,mixed> $event
     */
    public function toRemoteEvent(array $event): ?RemoteEvent
    {
        $id = trim((string) ($event['id'] ?? ''));

        if ('' === $id) {
            return null;
        }

        $startsAt = $this->instant($event['start'] ?? null);
        $endsAt   = $this->instant($event['end'] ?? null);

        if (null === $startsAt || null === $endsAt) {
            return null;
        }

        $isAllDay = true === ($event['isAllDay'] ?? false);
        $timeZone = true === $isAllDay ? null : $this->displayZone($event);
        $local    = $startsAt->setTimezone(new DateTimeZone($timeZone ?? 'UTC'));

        $uid = trim((string) ($event['iCalUId'] ?? ''));

        $jscalendar = [
            '@type'    => 'Event',
            'uid'      => '' === $uid ? $id : $uid,
            'title'    => trim((string) ($event['subject'] ?? '')),
            'start'    => $local->format('Y-m-d\TH:i:s'),
            'duration' => $this->duration($startsAt, $endsAt),
            'status'   => true === ($event['isCancelled'] ?? false) ? 'cancelled' : 'confirmed',
            'privacy'  => $this->privacyOf($event),
        ];

        if (null !== $timeZone) {
            $jscalendar['timeZone'] = $timeZone;
        }

        if (true === $isAllDay) {
            $jscalendar['showWithoutTime'] = true;
        }

        $jscalendar += $this->descriptionOf($event);
        $jscalendar += $this->locationsOf($event);
        $jscalendar += $this->virtualLocationsOf($event);
        $jscalendar += $this->freeBusyOf($event);
        $jscalendar += $this->keywordsOf($event);
        $jscalendar += $this->stampsOf($event);
        $jscalendar += $this->alertsOf($event);

        $participants = $this->participantsOf($event);

        if ([] !== $participants) {
            $jscalendar['participants'] = $participants;
        }

        $recurrence = $event['recurrence'] ?? null;

        if (true === is_array($recurrence)) {
            $rule = $this->recurrence->toJsCalendar($recurrence);

            if (null !== $rule) {
                $jscalendar['recurrenceRules'] = [$rule];
            }
        }

        return new RemoteEvent(
            remoteId:   $id,
            etag:       $this->etagOf($event),
            uid:        (string) $jscalendar['uid'],
            isDeleted:  false,
            jscalendar: $jscalendar,
            startsAt:   $startsAt,
            endsAt:     $endsAt,
        );
    }

    /**
     * One local event as the body of a Graph create or update.
     *
     * Every mapped property is sent on every write, including the empty ones.
     * A PATCH leaves out what it does not mention, so omitting an absent
     * description is indistinguishable from never having had one — and a user
     * who deletes the notes off an event would watch them come back on the next
     * pull. Sending the empty value is what makes a local clear a real clear.
     *
     * @return array<string,mixed>
     */
    public function toGraphEvent(CalendarEvent $event): array
    {
        $jscalendar = $event->jscalendar;
        $startsAt   = $event->startsAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $endsAt     = $event->endsAt ?? $startsAt;
        $zone       = true === $event->isAllDay ? null : $event->timeZone;

        return [
            'subject'     => (string) $event->title,
            'body'        => $this->bodyOut($jscalendar),
            'start'       => $this->dateTimeOut($startsAt, $zone),
            'end'         => $this->dateTimeOut($endsAt, $zone),
            'isAllDay'    => $event->isAllDay,
            'location'    => ['displayName' => (string) $event->location],
            'sensitivity' => $this->sensitivityOut($event->privacy),
            'showAs'      => $this->showAsOut($jscalendar),
            'categories'  => $this->categoriesOut($jscalendar),
            'attendees'   => $this->attendeesOut($jscalendar),
            // Explicitly null rather than omitted: null is how Graph is told to
            // turn a series back into a single event, and a rule removed here
            // has to remove the series there rather than leaving the local row
            // and the remote one describing different meetings.
            'recurrence'  => $this->recurrenceOut($jscalendar, $startsAt, $zone),
        ] + $this->reminderOut($event);
    }

    /**
     * One stored override as the body of a PATCH against Graph's own occurrence
     * resource.
     *
     * Graph turns a patched occurrence into an exception of the series, which is
     * exactly what a recurrenceOverride is on this side. The series' own write
     * cannot say it: `recurrence` is the pattern, and an occurrence that differs
     * from the pattern has no home in it.
     *
     * `subject` travels on every write and falls back to the series' title, the
     * same choice CalDavEventConverter::addOverrides() makes — an instance
     * renamed and then renamed back carries no title in its patch, and a payload
     * that omitted the field would leave the old name on that one occurrence in
     * Outlook forever.
     *
     * There is no cancellation here, deliberately. `isCancelled` is read-only at
     * Graph and cancelling a meeting is its own action that mails every
     * attendee, so an excluded instance is a DELETE against the occurrence —
     * which is the same answer this mapper's docblock gives for cancelling a
     * whole event, because it is the same restriction.
     *
     * @return array<string,mixed>
     */
    public function toGraphInstance(CalendarEvent $event, InstanceOverride $override): array
    {
        $zone = true === $event->isAllDay ? null : $event->timeZone;

        return [
            'subject' => $override->title ?? (string) $event->title,
            'start'   => $this->dateTimeOut($override->startsAt, $zone),
            'end'     => $this->dateTimeOut($override->endsAt, $zone),
        ];
    }

    /**
     * Where the rule originally put an instance somebody has changed.
     *
     * Graph states it on an exception entry as `originalStart`, a UTC instant,
     * beside the `start` it was moved to. That original is the instance's only
     * stable name — JSCalendar keys a recurrenceOverride by it — and taking the
     * moved start instead would file the patch under a date the expander never
     * looks up, so the instance would be drawn twice.
     *
     * Public because the driver, not the mapper, decides what an exception entry
     * becomes: the mapper never sees the delta window that says which entries
     * belong to which series.
     *
     * @param array<string,mixed> $event
     */
    public function originalStartOf(array $event): ?DateTimeImmutable
    {
        $originalStart = $this->stringOrNull($event['originalStart'] ?? null);

        if (null === $originalStart) {
            return null;
        }

        try {
            return new DateTimeImmutable($originalStart)->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * When one entry begins, as a UTC instant.
     *
     * Public for the one caller that needs it without the rest of the mapping:
     * an occurrence nothing has happened to carries no `originalStart` on some
     * tenants, and for such an entry the start IS the original one — the rule
     * put it there and nothing has moved it. That equality is the whole reason
     * this may stand in for originalStartOf(), and it holds only for
     * `type: occurrence`; an exception's start is where somebody dragged it,
     * which is not a name anything can look an override up by.
     *
     * @param array<string,mixed> $event
     */
    public function startOf(array $event): ?DateTimeImmutable
    {
        return $this->instant($event['start'] ?? null);
    }

    // ── Reading Graph ────────────────────────────────────────────────────────

    /**
     * Graph's single reminder as a JSCalendar alerts map, or nothing.
     *
     * Merged into the object with `+=` like the other partial readers here, so
     * an event with the reminder off contributes no `alerts` key at all rather
     * than an empty map — see CalendarEventWriter for why an empty map is worse
     * than an absent one.
     *
     * A negative `reminderMinutesBeforeStart` is refused. Graph does not produce
     * one, but the value arrives as decoded JSON from a tenant that may have
     * been written to by anything, and a positive offset would silently become
     * an alert AFTER the meeting started.
     *
     * @param array<string,mixed> $event
     *
     * @return array<string,mixed>
     */
    private function alertsOf(array $event): array
    {
        if (true !== ($event['isReminderOn'] ?? false)) {
            return [];
        }

        $minutes = $event['reminderMinutesBeforeStart'] ?? null;

        if (false === is_int($minutes) || 0 > $minutes) {
            return [];
        }

        $alert = $this->alerts->offsetAlert(
            $this->alerts->isoOffset(-$minutes * 60),
            AlertAction::Display,
        );

        return null === $alert ? [] : ['alerts' => [$alert->key => $alert->toJsCalendar()]];
    }

    /**
     * The one local alert Graph can hold, or the reminder switched off.
     *
     * The LAST display alert before the start wins — the one nearest it. Graph
     * has room for exactly one, and choosing the earliest instead would give an
     * Outlook user a day's notice and then nothing at the moment the meeting
     * begins, which is the notification people actually rely on.
     *
     * Both keys are always written, which is the difference from Google. `false`
     * here is an unambiguous "no reminder", so an alert removed in plMail is
     * removed in Outlook too; there is no "use the calendar's default" state for
     * it to be confused with.
     *
     * @return array{isReminderOn: bool, reminderMinutesBeforeStart?: int}
     */
    private function reminderOut(CalendarEvent $event): array
    {
        $nearest = null;

        foreach ($this->alerts->alertsOf($event) as $alert) {
            $seconds = $alert->offsetSeconds;

            // Display only, before the start only, measured from the start only
            // — everything else has no counterpart, and approximating one would
            // move somebody's reminder without telling them.
            if (
                AlertAction::Display !== $alert->action
                || null === $seconds || 0 < $seconds || true === $alert->relativeToEnd
            ) {
                continue;
            }

            $nearest = null === $nearest ? $seconds : max($nearest, $seconds);
        }

        if (null === $nearest) {
            return ['isReminderOn' => false];
        }

        return [
            'isReminderOn'               => true,
            // Rounded rather than truncated, the same choice GoogleEventMapper
            // makes: early is a reminder, late is a notification about a meeting
            // you are already in.
            'reminderMinutesBeforeStart' => (int) round(abs($nearest) / 60),
        ];
    }

    /**
     * The zone the event is displayed in.
     *
     * originalStartTimeZone first — see the class docblock. Both names go
     * through the Windows table; "tzone://Microsoft/Custom", which is what a
     * mailbox with hand-edited DST rules answers, resolves to nothing and
     * leaves the event on the zone of its start instead.
     *
     * @param array<string,mixed> $event
     */
    private function displayZone(array $event): ?string
    {
        $original = $this->zones->toIana($this->stringOrNull($event['originalStartTimeZone'] ?? null));

        if (null !== $original) {
            return $original;
        }

        $start = $event['start'] ?? null;

        return $this->zones->toIana(is_array($start) ? $this->stringOrNull($start['timeZone'] ?? null) : null);
    }

    /**
     * A Graph dateTimeTimeZone as a UTC instant.
     *
     * Graph writes seven fractional digits ("2026-08-04T09:00:00.0000000"),
     * which is more precision than PHP's parser keeps but not more than it
     * accepts.
     */
    private function instant(mixed $dateTimeTimeZone): ?DateTimeImmutable
    {
        if (false === is_array($dateTimeTimeZone)) {
            return null;
        }

        $dateTime = $this->stringOrNull($dateTimeTimeZone['dateTime'] ?? null);

        if (null === $dateTime) {
            return null;
        }

        try {
            $parsed = new DateTimeImmutable(
                $dateTime,
                $this->zones->zoneFor($this->stringOrNull($dateTimeTimeZone['timeZone'] ?? null)),
            );
        } catch (\Exception) {
            return null;
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Organiser and attendees, keyed by address, with their roles accumulated.
     *
     * Roles are MERGED onto whoever already holds the address, never assigned
     * over the top. One person is routinely both: Graph lists the organiser in
     * `organizer` and again in `attendees` when they are going, which is what
     * Outlook does for every meeting anybody schedules for themselves. Keyed by
     * address and written in property order, the attendee line would replace the
     * organiser line — the only participant carrying `owner` loses it, the event
     * ends up with no organiser, and the invite card has nobody to answer and so
     * offers no answer. This is the same defect IcsEventExtractor::participantsOf()
     * carries a comment about; it is the same bug because it is the same shape of
     * data arriving by a second route.
     *
     * The organiser is read first, so an attendee line's response lands on top
     * of an organiser line that has none — which is the direction that adds
     * information rather than removing it.
     *
     * @param array<string,mixed> $event
     *
     * @return array<string,array<string,mixed>>
     */
    private function participantsOf(array $event): array
    {
        $participants = [];

        $organizer = $event['organizer'] ?? null;

        if (true === is_array($organizer)) {
            $this->mergeParticipant($participants, $organizer['emailAddress'] ?? null, ['owner']);
        }

        $attendees = $event['attendees'] ?? null;

        foreach (true === is_array($attendees) ? $attendees : [] as $attendee) {
            if (false === is_array($attendee)) {
                continue;
            }

            $type  = strtolower((string) ($attendee['type'] ?? 'required'));
            $roles = 'optional' === $type ? ['attendee', 'optional'] : ['attendee'];

            $key = $this->mergeParticipant($participants, $attendee['emailAddress'] ?? null, $roles);

            if (null === $key) {
                continue;
            }

            if ('resource' === $type) {
                $participants[$key]['kind'] = 'resource';
            }

            $status = $this->responseOf($attendee);

            // Only ever written when the line states one, so a second mention
            // of the same person cannot blank an answer already read.
            if (null !== $status) {
                $participants[$key]['participationStatus'] = $status;
            }
        }

        return $participants;
    }

    /**
     * @param array<string,array<string,mixed>> $participants
     * @param list<string>                      $roles
     *
     * @return string|null the key this landed on, or null when there was no address
     */
    private function mergeParticipant(array &$participants, mixed $emailAddress, array $roles): ?string
    {
        if (false === is_array($emailAddress)) {
            return null;
        }

        $address = trim((string) ($emailAddress['address'] ?? ''));

        if ('' === $address) {
            return null;
        }

        $key = mb_strtolower($address);

        $participant = $participants[$key] ?? [
            '@type' => 'Participant',
            'email' => $address,
            'roles' => [],
        ];

        foreach ($roles as $role) {
            $participant['roles'][$role] = true;
        }

        $name = trim((string) ($emailAddress['name'] ?? ''));

        // Graph fills `name` with the address itself when it knows no display
        // name, which is not a name and would render as one.
        if ('' !== $name && $name !== $address) {
            $participant['name'] = $name;
        }

        $participants[$key] = $participant;

        return $key;
    }

    /**
     * @param array<string,mixed> $attendee
     */
    private function responseOf(array $attendee): ?string
    {
        $status = $attendee['status'] ?? null;

        if (false === is_array($status)) {
            return null;
        }

        $response = strtolower(trim((string) ($status['response'] ?? '')));

        return self::RESPONSES[$response] ?? null;
    }

    /**
     * @param array<string,mixed> $event
     *
     * @return array<string,mixed>
     */
    private function descriptionOf(array $event): array
    {
        $body = $event['body'] ?? null;

        if (false === is_array($body)) {
            return [];
        }

        $content = $this->stringOrNull($body['content'] ?? null);

        if (null === $content) {
            return [];
        }

        $isHtml = 'html' === strtolower((string) ($body['contentType'] ?? 'text'));

        return [
            'description'            => $content,
            'descriptionContentType' => true === $isHtml ? 'text/html' : 'text/plain',
        ];
    }

    /**
     * @param array<string,mixed> $event
     *
     * @return array<string,mixed>
     */
    private function locationsOf(array $event): array
    {
        $location = $event['location'] ?? null;
        $name     = is_array($location) ? $this->stringOrNull($location['displayName'] ?? null) : null;

        if (null === $name) {
            return [];
        }

        // Keyed "1" like CalendarEventWriter's, so the projected
        // CalendarEvent::$location column is read from the same place whichever
        // writer produced the object.
        return ['locations' => ['1' => ['@type' => 'Location', 'name' => $name]]];
    }

    /**
     * @param array<string,mixed> $event
     *
     * @return array<string,mixed>
     */
    private function virtualLocationsOf(array $event): array
    {
        $meeting = $event['onlineMeeting'] ?? null;
        $url     = is_array($meeting) ? $this->stringOrNull($meeting['joinUrl'] ?? null) : null;

        $url ??= $this->stringOrNull($event['onlineMeetingUrl'] ?? null);

        if (null === $url) {
            return [];
        }

        return ['virtualLocations' => ['1' => ['@type' => 'VirtualLocation', 'uri' => $url]]];
    }

    /**
     * @param array<string,mixed> $event
     *
     * @return array<string,mixed>
     */
    private function freeBusyOf(array $event): array
    {
        $showAs = strtolower(trim((string) ($event['showAs'] ?? '')));

        if ('' === $showAs || 'unknown' === $showAs) {
            return [];
        }

        return [
            'freeBusyStatus'     => 'free' === $showAs ? 'free' : 'busy',
            'plmail:graphShowAs' => $showAs,
        ];
    }

    /**
     * JSCalendar keywords are a set, not a list (RFC 8984 §4.2.9) — a String
     * to Boolean map, exactly like roles.
     *
     * @param array<string,mixed> $event
     *
     * @return array<string,mixed>
     */
    private function keywordsOf(array $event): array
    {
        $categories = $event['categories'] ?? null;

        if (false === is_array($categories)) {
            return [];
        }

        $keywords = [];

        foreach ($categories as $category) {
            $name = $this->stringOrNull($category);

            if (null !== $name) {
                $keywords[$name] = true;
            }
        }

        return [] === $keywords ? [] : ['keywords' => $keywords];
    }

    /**
     * created and updated, in the UTCDateTime form RFC 8984 §4.1.4 requires —
     * a trailing Z, not an offset.
     *
     * @param array<string,mixed> $event
     *
     * @return array<string,mixed>
     */
    private function stampsOf(array $event): array
    {
        $stamps = [];

        foreach (['createdDateTime' => 'created', 'lastModifiedDateTime' => 'updated'] as $graph => $key) {
            $value = $this->stringOrNull($event[$graph] ?? null);

            if (null === $value) {
                continue;
            }

            try {
                $stamps[$key] = (new DateTimeImmutable($value))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z');
            } catch (\Exception) {
                // A timestamp Graph mangled is not a reason to lose the event.
                continue;
            }
        }

        return $stamps;
    }

    /**
     * @param array<string,mixed> $event
     */
    private function privacyOf(array $event): string
    {
        $sensitivity = strtolower(trim((string) ($event['sensitivity'] ?? '')));

        return self::SENSITIVITIES[$sensitivity] ?? EventPrivacy::Public->value;
    }

    /**
     * Graph spells its version marker `@odata.etag`, and it arrives quoted
     * (W/"DwAAABYAAAA…"). Kept verbatim: the interface says an etag is opaque
     * and compared only for equality, and stripping the weak-validator prefix
     * here would make it unequal to the one If-Match has to send back.
     *
     * @param array<string,mixed> $event
     */
    private function etagOf(array $event): ?string
    {
        return $this->stringOrNull($event['@odata.etag'] ?? null);
    }

    // ── Writing Graph ────────────────────────────────────────────────────────

    /**
     * @return array<string,string>
     */
    private function dateTimeOut(DateTimeImmutable $instant, ?string $zone): array
    {
        if (null === $zone) {
            // All-day and floating events are stored as local midnight in UTC,
            // which is the same shape Graph stores an all-day event in.
            return [
                'dateTime' => $instant->format('Y-m-d\TH:i:s'),
                'timeZone' => 'UTC',
            ];
        }

        return [
            'dateTime' => $instant->setTimezone(new DateTimeZone($zone))->format('Y-m-d\TH:i:s'),
            'timeZone' => $this->zones->toGraph($zone),
        ];
    }

    /**
     * @param array<string,mixed> $jscalendar
     *
     * @return array<string,string>
     */
    private function bodyOut(array $jscalendar): array
    {
        $description = $this->stringOrNull($jscalendar['description'] ?? null) ?? '';
        $type        = strtolower((string) ($jscalendar['descriptionContentType'] ?? 'text/plain'));

        return [
            'contentType' => 'text/html' === $type ? 'html' : 'text',
            'content'     => $description,
        ];
    }

    private function sensitivityOut(EventPrivacy $privacy): string
    {
        return match ($privacy) {
            EventPrivacy::Public  => 'normal',
            EventPrivacy::Private => 'private',
            EventPrivacy::Secret  => 'confidential',
        };
    }

    /**
     * The preserved Graph value wins over the JSCalendar one, so an
     * out-of-office block edited here goes back as out-of-office rather than as
     * a plain busy — see the class docblock. Nothing in plMail edits
     * freeBusyStatus, so the two can only disagree by the pull having narrowed
     * it.
     *
     * @param array<string,mixed> $jscalendar
     */
    private function showAsOut(array $jscalendar): string
    {
        $preserved = $this->stringOrNull($jscalendar['plmail:graphShowAs'] ?? null);

        if (null !== $preserved) {
            return strtolower($preserved);
        }

        return 'free' === strtolower((string) ($jscalendar['freeBusyStatus'] ?? '')) ? 'free' : 'busy';
    }

    /**
     * @param array<string,mixed> $jscalendar
     *
     * @return list<string>
     */
    private function categoriesOut(array $jscalendar): array
    {
        $keywords = $jscalendar['keywords'] ?? null;

        if (false === is_array($keywords)) {
            return [];
        }

        $categories = [];

        foreach ($keywords as $keyword => $isSet) {
            if (true === $isSet && true === is_string($keyword) && '' !== $keyword) {
                $categories[] = $keyword;
            }
        }

        return $categories;
    }

    /**
     * Participants carrying the attendee role, as Graph attendees.
     *
     * The organiser-only entry is dropped rather than sent: Graph refuses a
     * foreign organizer, and sending the mailbox owner back as an attendee of
     * their own meeting would make Outlook show them invited to it. An entry
     * that is both keeps its attendee line, which is the one Graph can accept.
     *
     * @param array<string,mixed> $jscalendar
     *
     * @return list<array<string,mixed>>
     */
    private function attendeesOut(array $jscalendar): array
    {
        $participants = $jscalendar['participants'] ?? null;

        if (false === is_array($participants)) {
            return [];
        }

        $attendees = [];

        foreach ($participants as $participant) {
            if (false === is_array($participant)) {
                continue;
            }

            $roles = is_array($participant['roles'] ?? null) ? $participant['roles'] : [];

            if (true !== ($roles['attendee'] ?? false)) {
                continue;
            }

            $address = $this->stringOrNull($participant['email'] ?? null);

            if (null === $address) {
                continue;
            }

            $email = ['address' => $address];
            $name  = $this->stringOrNull($participant['name'] ?? null);

            if (null !== $name) {
                $email['name'] = $name;
            }

            $status = ParticipationStatus::fromJsCalendar(
                $this->stringOrNull($participant['participationStatus'] ?? null),
            );

            $attendees[] = [
                'type'         => true === ($roles['optional'] ?? false) ? 'optional' : 'required',
                'emailAddress' => $email,
                'status'       => ['response' => $this->responseOut($status)],
            ];
        }

        return $attendees;
    }

    private function responseOut(ParticipationStatus $status): string
    {
        return match ($status) {
            ParticipationStatus::NeedsAction => 'notResponded',
            ParticipationStatus::Accepted    => 'accepted',
            ParticipationStatus::Declined    => 'declined',
            ParticipationStatus::Tentative   => 'tentativelyAccepted',
        };
    }

    /**
     * @param array<string,mixed> $jscalendar
     *
     * @return array<string,mixed>|null
     */
    private function recurrenceOut(array $jscalendar, DateTimeImmutable $startsAt, ?string $zone): ?array
    {
        $rules = $jscalendar['recurrenceRules'] ?? null;
        $rule  = is_array($rules) ? ($rules[0] ?? null) : null;

        if (false === is_array($rule)) {
            return null;
        }

        $localStart = null === $zone
            ? $startsAt
            : $startsAt->setTimezone(new DateTimeZone($zone));

        return $this->recurrence->toGraph($rule, $localStart);
    }

    // ── Shared ───────────────────────────────────────────────────────────────

    /**
     * ISO 8601, which is how JSCalendar says how long something is.
     *
     * The same formatter CalendarEventWriter carries privately, and deliberately
     * a second copy rather than a shared helper. This object has to be complete
     * *before* the writer ever sees it — RemoteEvent::$jscalendar is what the
     * engine hands around and what an .ics export would read — so extracting the
     * one shared function would mean giving CalendarEventWriter a public surface
     * for the benefit of a pure two-instant calculation.
     */
    private function duration(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): string
    {
        $seconds = max(0, $endsAt->getTimestamp() - $startsAt->getTimestamp());

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
     * A Graph resource arrives as decoded JSON, so every key can hold anything
     * — one bad cast is a TypeError that fails the whole sweep rather than one
     * event.
     */
    private function stringOrNull(mixed $value): ?string
    {
        if (false === is_string($value) || '' === trim($value)) {
            return null;
        }

        return $value;
    }
}
