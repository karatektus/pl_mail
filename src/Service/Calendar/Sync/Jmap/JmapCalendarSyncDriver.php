<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\Jmap;

use App\Domain\DTO\Calendar\CalendarChangeSet;
use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Calendar\RemoteCalendar;
use App\Domain\DTO\Calendar\RemoteEvent;
use App\Domain\DTO\Calendar\RemoteWriteResult;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\CalendarSyncThrottledException;
use App\Domain\Exception\JmapApiException;
use App\Domain\Exception\JmapResyncRequiredException;
use App\Domain\Exception\JmapThrottledException;
use App\Domain\Interface\CalendarSyncDriverInterface;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Mail\Account;
use App\Infrastructure\JmapClient\JmapCapability;
use App\Infrastructure\JmapClient\JmapClient;
use App\Service\User\UserTimezoneResolver;

/**
 * JMAP for Calendars, behind the sync driver contract.
 *
 * Rides the JMAP mail account's own session, as the Google and Microsoft
 * drivers ride their mail grants: a JMAP server that offers calendars offers
 * them to the same login, so there is no second connection to make.
 *
 * ## Nothing to translate
 *
 * A JMAP CalendarEvent IS a JSCalendar Event (RFC 8984) with a few JMAP
 * properties beside it — its id, which calendars it is in — and JSCalendar is
 * what CalendarEvent stores as its truth. So pull hands the engine the
 * server's object with the JMAP envelope taken off, and push sends the stored
 * object with the envelope put back. No field is mapped and none is lost:
 * participants, alerts, links and recurrenceOverrides go through as they are.
 * The one thing derived is the UTC start and end the engine wants beside the
 * object, from `start`, `timeZone` and `duration`.
 *
 * ## One state for every calendar
 *
 * JMAP's state is per data type, not per calendar: CalendarEvent/changes
 * answers for the whole account. Each Calendar row stores that state as its
 * sync token, reads the changes from it, and keeps only what concerns itself —
 * an event moved to another calendar is a tombstone here and a create there,
 * which is what it is. A first read is CalendarEvent/query filtered to the
 * calendar, and stores the state it read at.
 *
 * Recurring events are pulled as series, never expanded, for the reason the
 * Google driver gives: RecurrenceMaterialiser expands every series the same
 * way, whichever server it came from.
 */
final readonly class JmapCalendarSyncDriver implements CalendarSyncDriverInterface
{
    /** How many changes one CalendarEvent/changes call may report. */
    private const int MAX_CHANGES = 500;

    /**
     * Properties that belong to the JMAP envelope and not to JSCalendar.
     *
     * Taken off on the way in, so the stored object is pure RFC 8984, and
     * never sent on the way out except calendarIds, which push sets itself.
     */
    private const array ENVELOPE = [
        'id', 'calendarIds', 'isDraft', 'isOrigin', 'utcStart', 'utcEnd', 'blobId', 'baseEventId',
        'mayInviteSelf', 'mayInviteOthers', 'hideAttendees', 'useDefaultAlerts', 'created', 'updated',
    ];

    /**
     * The JSCalendar properties an update sends as null when the stored
     * object has none, so a location or a rule removed here is removed there.
     * An update replaces only the properties it names; everything else plMail
     * does not model is left as the server has it.
     */
    private const array CLEARABLE = [
        'description', 'locations', 'virtualLocations', 'links', 'recurrenceRule',
        'recurrenceOverrides', 'participants', 'alerts', 'keywords',
    ];

    public function __construct(
        private JmapClient           $jmap,
        private UserTimezoneResolver $timezones,
    ) {
    }

    /**
     * Asked of the account alone, without the network: the source lister
     * asks this of every account on the settings screen. A JMAP server
     * without calendars answers discover() with nothing.
     */
    public function supports(CalendarSource $source): bool
    {
        return true === $source->account?->isJmap();
    }

    /**
     * @return list<RemoteCalendar>
     *
     * @throws CalendarSyncException
     */
    public function discover(CalendarSource $source): array
    {
        $account   = $this->accountOf($source->account);
        $accountId = $this->calendarAccountId($account);

        if (null === $accountId) {
            return [];
        }

        $result = $this->call($account, [
            ['Calendar/get', ['accountId' => $accountId, 'ids' => null], 'c'],
        ], 'c', 'Calendar/get');

        $calendars = [];

        foreach (is_array($result['list'] ?? null) ? $result['list'] : [] as $calendar) {
            if (false === is_array($calendar) || false === is_string($calendar['id'] ?? null)) {
                continue;
            }

            $rights = is_array($calendar['myRights'] ?? null) ? $calendar['myRights'] : [];
            $color  = $calendar['color'] ?? null;

            $calendars[] = new RemoteCalendar(
                remoteId:   $calendar['id'],
                name:       (string) ($calendar['name'] ?? $calendar['id']),
                color:      is_string($color) && 1 === preg_match('/^#[0-9a-f]{6}$/i', $color) ? strtolower($color) : null,
                timeZone:   is_string($calendar['timeZone'] ?? null) ? $calendar['timeZone'] : null,
                // Without the right to write every event, the safe reading is
                // read-only: a push the server refuses is a failed sync, and
                // mayWriteOwn alone does not cover events somebody else made.
                isReadOnly: true !== ($rights['mayWriteAll'] ?? true),
                isPrimary:  true === ($calendar['isDefault'] ?? false),
            );
        }

        return $calendars;
    }

    /**
     * @throws CalendarSyncException
     */
    public function pull(Calendar $calendar, ?string $syncToken): CalendarChangeSet
    {
        $account   = $this->accountOf(CalendarSource::ofCalendar($calendar)?->account);
        $accountId = $this->calendarAccountId($account) ?? throw new CalendarSyncPermanentException('The JMAP server no longer offers calendars for this account.');
        $zone      = $calendar->timeZone ?? $this->timezones->nameFor($calendar->usr);

        if (null === $syncToken) {
            return $this->readAll($account, $accountId, $calendar, $zone);
        }

        $changed   = [];
        $destroyed = [];
        $state     = $syncToken;

        do {
            try {
                $changes = $this->jmap->call($account, [JmapCapability::CALENDARS], [
                    ['CalendarEvent/changes', ['accountId' => $accountId, 'sinceState' => $state, 'maxChanges' => self::MAX_CHANGES], 'c'],
                ])->get('c', 'CalendarEvent/changes');
            } catch (JmapResyncRequiredException) {
                return CalendarChangeSet::resyncRequired();
            } catch (JmapApiException $e) {
                throw $this->translate($e);
            }

            foreach (['created', 'updated'] as $kind) {
                foreach (is_array($changes[$kind] ?? null) ? $changes[$kind] : [] as $id) {
                    if (true === is_string($id)) {
                        $changed[$id] = true;
                    }
                }
            }

            foreach (is_array($changes['destroyed'] ?? null) ? $changes['destroyed'] : [] as $id) {
                if (true === is_string($id)) {
                    $destroyed[$id] = true;
                    unset($changed[$id]);
                }
            }

            $state = is_string($changes['newState'] ?? null) ? $changes['newState'] : $state;
        } while (true === ($changes['hasMoreChanges'] ?? false));

        $events = [];

        if ([] !== $changed) {
            [$objects] = $this->get($account, $accountId, array_map(strval(...), array_keys($changed)));

            foreach ($objects as $object) {
                $id = (string) $object['id'];

                // Changed, but not in this calendar: moved to another one, or
                // never here. A tombstone either way — for an event this
                // calendar never held the engine finds nothing to remove.
                if (true !== ($object['calendarIds'][(string) $calendar->remoteId] ?? false)) {
                    $events[] = RemoteEvent::deleted($id);

                    continue;
                }

                $event = $this->toRemoteEvent($object, $zone);

                if (null !== $event) {
                    $events[] = $event;
                }
            }
        }

        foreach (array_keys($destroyed) as $id) {
            $events[] = RemoteEvent::deleted((string) $id);
        }

        return new CalendarChangeSet($events, $state);
    }

    /**
     * @throws CalendarSyncException
     */
    public function push(Calendar $calendar, CalendarEvent $event): RemoteWriteResult
    {
        $account   = $this->accountOf(CalendarSource::ofCalendar($calendar)?->account);
        $accountId = $this->calendarAccountId($account) ?? throw new CalendarSyncPermanentException('The JMAP server no longer offers calendars for this account.');
        $object    = self::toWire(array_diff_key($event->jscalendar, array_flip(self::ENVELOPE)));
        $remoteId  = $event->remoteId;

        $object['@type'] = 'Event';

        if (null === $remoteId) {
            $object['uid']         = '' !== $event->uid ? $event->uid : ($object['uid'] ?? null);
            $object['calendarIds'] = [(string) $calendar->remoteId => true];

            $result = $this->call($account, [
                ['CalendarEvent/set', ['accountId' => $accountId, 'create' => ['e' => array_filter($object, static fn (mixed $value): bool => null !== $value)]], 's'],
            ], 's', 'CalendarEvent/set');

            $id = $result['created']['e']['id'] ?? null;

            if (false === is_string($id)) {
                throw $this->refusal('create', $result['notCreated']['e'] ?? null);
            }

            return new RemoteWriteResult($id);
        }

        // The UID names the event everywhere it is, and a server refuses to
        // change it; it is not sent on an update.
        unset($object['uid']);

        foreach (self::CLEARABLE as $property) {
            $object[$property] ??= null;
        }

        $result = $this->call($account, [
            ['CalendarEvent/set', ['accountId' => $accountId, 'update' => [$remoteId => $object]], 's'],
        ], 's', 'CalendarEvent/set');

        if (true === array_key_exists($remoteId, is_array($result['notUpdated'] ?? null) ? $result['notUpdated'] : [])) {
            throw $this->refusal('update', $result['notUpdated'][$remoteId]);
        }

        // No etag: JMAP versions a whole data type, not an object, so there is
        // none to give — and a null makes the next pull re-read the event,
        // which RemoteWriteResult names as the safe answer.
        return new RemoteWriteResult($remoteId);
    }

    /**
     * Idempotent, as the contract asks: an event the server no longer has is
     * notFound in notDestroyed, which is the end state wanted.
     *
     * @throws CalendarSyncException
     */
    public function delete(Calendar $calendar, CalendarEvent $event): void
    {
        $remoteId = $event->remoteId;

        if (null === $remoteId) {
            return;
        }

        $account   = $this->accountOf(CalendarSource::ofCalendar($calendar)?->account);
        $accountId = $this->calendarAccountId($account);

        if (null === $accountId) {
            return;
        }

        $result = $this->call($account, [
            ['CalendarEvent/set', ['accountId' => $accountId, 'destroy' => [$remoteId]], 's'],
        ], 's', 'CalendarEvent/set');

        $refused = $result['notDestroyed'][$remoteId] ?? null;

        if (null !== $refused && 'notFound' !== ($refused['type'] ?? null)) {
            throw $this->refusal('delete', $refused);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Every event in one calendar, and the state it was read at.
     *
     * The state comes from the CalendarEvent/get, which answers with the state
     * its objects are at — the position the next changes are read from.
     *
     * @throws CalendarSyncException
     */
    private function readAll(Account $account, string $accountId, Calendar $calendar, string $zone): CalendarChangeSet
    {
        $result = $this->call($account, [
            ['CalendarEvent/query', ['accountId' => $accountId, 'filter' => ['inCalendar' => (string) $calendar->remoteId]], 'q'],
        ], 'q', 'CalendarEvent/query');

        $ids = array_values(array_filter(is_array($result['ids'] ?? null) ? $result['ids'] : [], 'is_string'));

        [$objects, $state] = $this->get($account, $accountId, $ids);

        $events = [];

        foreach ($objects as $object) {
            $event = $this->toRemoteEvent($object, $zone);

            if (null !== $event) {
                $events[] = $event;
            }
        }

        // An empty calendar has nothing for get to answer about, and still
        // needs a position to read changes from.
        if (null === $state) {
            $state = (string) ($this->call($account, [
                ['CalendarEvent/get', ['accountId' => $accountId, 'ids' => [], 'properties' => ['id']], 'g'],
            ], 'g', 'CalendarEvent/get')['state'] ?? '');
        }

        return new CalendarChangeSet($events, '' === $state ? null : $state);
    }

    /**
     * CalendarEvent/get, chunked to what the server allows.
     *
     * @param list<string> $ids
     *
     * @return array{0: list<array<string, mixed>>, 1: string|null} the objects, and the state
     *
     * @throws CalendarSyncException
     */
    private function get(Account $account, string $accountId, array $ids): array
    {
        $objects = [];
        $state   = null;

        try {
            $limit = max(1, $this->jmap->session($account)->coreLimit('maxObjectsInGet'));
        } catch (JmapApiException $e) {
            throw $this->translate($e);
        }

        foreach (array_chunk($ids, $limit) as $chunk) {
            $result = $this->call($account, [
                ['CalendarEvent/get', ['accountId' => $accountId, 'ids' => $chunk], 'g'],
            ], 'g', 'CalendarEvent/get');

            $state ??= is_string($result['state'] ?? null) ? $result['state'] : null;

            foreach (is_array($result['list'] ?? null) ? $result['list'] : [] as $object) {
                if (true === is_array($object) && true === is_string($object['id'] ?? null)) {
                    $objects[] = $object;
                }
            }
        }

        return [$objects, $state];
    }

    /**
     * The server's object as a RemoteEvent: the envelope off, the instants on.
     *
     * Null for an object the engine could not store — one with no start, or a
     * start or zone that does not parse — rather than an event at the wrong
     * time.
     *
     * @param array<string, mixed> $object
     */
    private function toRemoteEvent(array $object, string $fallbackZone): ?RemoteEvent
    {
        $instants = self::instants($object, $fallbackZone);

        if (null === $instants) {
            return null;
        }

        $jscalendar          = self::fromWire(array_diff_key($object, array_flip(self::ENVELOPE)));
        $jscalendar['@type'] = 'Event';

        return new RemoteEvent(
            remoteId:   (string) $object['id'],
            etag:       null,
            uid:        is_string($object['uid'] ?? null) && '' !== $object['uid'] ? $object['uid'] : (string) $object['id'],
            jscalendar: $jscalendar,
            startsAt:   $instants[0],
            endsAt:     $instants[1],
        );
    }

    /**
     * The JSCalendar plMail stores, in the shape the JMAP Calendars draft
     * sends: one `recurrenceRule` object where RFC 8984 has a
     * `recurrenceRules` list, and no excluded rules at all.
     *
     * The draft is built on JSCalendar's revision, which made the change.
     * Stalwart 0.16 follows it and refuses `recurrenceRules` and
     * `excludedRecurrenceRules` in any form, null and [] included — and since
     * an update clears what it does not set, every edit made here was
     * refused, and the engine gave the event up. plMail stores one rule at
     * most (CalendarEventWriter), so the first is the whole of it. Excluded
     * rules are dropped: nothing here writes one, and no form of them is one
     * this server accepts.
     *
     * A server still on RFC 8984's list would refuse `recurrenceRule` the
     * same way. None is known to offer JMAP calendars: Fastmail's session
     * has no calendars capability.
     *
     * @param array<string, mixed> $jscalendar
     *
     * @return array<string, mixed>
     */
    private static function toWire(array $jscalendar): array
    {
        if (true === array_key_exists('recurrenceRules', $jscalendar)) {
            $rules = $jscalendar['recurrenceRules'];

            $jscalendar['recurrenceRule'] = is_array($rules) && is_array($rules[0] ?? null) ? $rules[0] : null;
        }

        unset($jscalendar['recurrenceRules'], $jscalendar['excludedRecurrenceRules']);

        return $jscalendar;
    }

    /**
     * The server's event in the shape plMail stores, RFC 8984's: the draft's
     * single `recurrenceRule` back as a one-rule `recurrenceRules`. An event
     * that already has the list — a server on RFC 8984 — keeps it.
     *
     * @param array<string, mixed> $jscalendar
     *
     * @return array<string, mixed>
     */
    private static function fromWire(array $jscalendar): array
    {
        if (true === array_key_exists('recurrenceRule', $jscalendar)) {
            if (false === isset($jscalendar['recurrenceRules']) && is_array($jscalendar['recurrenceRule'])) {
                $jscalendar['recurrenceRules'] = [$jscalendar['recurrenceRule']];
            }

            unset($jscalendar['recurrenceRule']);
        }

        return $jscalendar;
    }

    /**
     * An event's UTC start and exclusive end, from its JSCalendar times.
     *
     * The duration is added in the event's own zone and only then converted,
     * so an hour-long meeting on the night the clocks change is still an hour
     * long. A floating event — no timeZone — is read in the calendar's zone,
     * which is what RFC 8984 leaves to the client and what a person reading
     * the calendar expects; an all-day one in UTC, for the reason below.
     *
     * @param array<string, mixed> $object
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    public static function instants(array $object, string $fallbackZone): ?array
    {
        $start = $object['start'] ?? null;

        if (false === is_string($start) || '' === $start) {
            return null;
        }

        // An all-day event is floating at midnight UTC, whatever the
        // calendar's zone — plMail's own rule (CalendarTimeResolver::
        // eventZone()), and the one every view draws it by. Read in the
        // calendar's zone, Berlin's midnight was stored as 23:00 the day
        // before, and the event was drawn across two days.
        $zoneName = match (true) {
            is_string($object['timeZone'] ?? null) && '' !== $object['timeZone'] => $object['timeZone'],
            true === ($object['showWithoutTime'] ?? false)                        => 'UTC',
            default                                                               => $fallbackZone,
        };

        try {
            $zone     = new \DateTimeZone($zoneName);
            $local    = new \DateTimeImmutable($start, $zone);
            $duration = new \DateInterval(is_string($object['duration'] ?? null) && '' !== $object['duration'] ? $object['duration'] : 'PT0S');
        } catch (\Exception) {
            return null;
        }

        $utc = new \DateTimeZone('UTC');

        return [$local->setTimezone($utc), $local->add($duration)->setTimezone($utc)];
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>, 2: string}> $calls
     *
     * @return array<string, mixed>
     *
     * @throws CalendarSyncException
     */
    private function call(Account $account, array $calls, string $callId, string $name): array
    {
        try {
            return $this->jmap->call($account, [JmapCapability::CALENDARS], $calls)->get($callId, $name);
        } catch (JmapResyncRequiredException $e) {
            throw new CalendarResyncRequiredException($e->getMessage());
        } catch (JmapApiException $e) {
            throw $this->translate($e);
        }
    }

    /**
     * A JMAP refusal in the calendar engine's terms: throttled is throttled,
     * the credentials or the right to the calendar refused is permanent, and
     * everything else is worth another sweep.
     */
    private function translate(JmapApiException $e): CalendarSyncException
    {
        if ($e instanceof JmapThrottledException) {
            return new CalendarSyncThrottledException($e->getMessage(), $e->getStatus(), $e->getRetryAfterSeconds(), $e);
        }

        if (true === in_array($e->getStatus(), [401, 403], true) || true === in_array($e->getType(), ['forbidden', 'accountNotFound', 'accountNotSupportedByMethod'], true)) {
            return new CalendarSyncPermanentException($e->getMessage(), $e->getStatus(), $e);
        }

        return new CalendarSyncException($e->getMessage(), $e->getStatus(), $e);
    }

    /**
     * @param mixed $error a SetError, or nothing when the server said nothing
     */
    private function refusal(string $operation, mixed $error): CalendarSyncException
    {
        $type = is_array($error) && is_string($error['type'] ?? null) ? $error['type'] : 'unknown';

        return new CalendarSyncPermanentException(sprintf('The JMAP server refused the event %s (%s).', $operation, $type));
    }

    private function calendarAccountId(Account $account): ?string
    {
        try {
            return $this->jmap->session($account)->accountFor(JmapCapability::CALENDARS);
        } catch (JmapApiException $e) {
            throw $this->translate($e);
        }
    }

    /**
     * @throws CalendarSyncPermanentException
     */
    private function accountOf(?Account $account): Account
    {
        if (null === $account || false === $account->isJmap()) {
            throw new CalendarSyncPermanentException('This calendar is not one of a JMAP account.');
        }

        return $account;
    }
}
