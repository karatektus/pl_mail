<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Listener;

use App\Domain\Enum\Calendar\CalendarChangeKind;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Writes the calendar change log, from inside the flush that causes the change.
 *
 * A listener rather than a recorder the writers call, and the difference is the
 * whole point. The design note this replaced rejected a partial log in the
 * strongest terms — "a log that recorded a quarter of the writes would be worse
 * than none… it is a lie with a number on it" — and then reached for a recorder
 * beside MailChangeRecorder, called by every writer. Mail can be served that
 * way because its JMAP-visible properties move through a small number of
 * intentional services.
 *
 * Calendars cannot. CalendarEvent exposes public mutable properties, so a field
 * changes wherever somebody assigns one and lets the flush carry it: an RSVP in
 * InviteResponder, a SEQUENCE bump in EventReconciler, the recurrence flags in
 * RecurrenceMaterialiser — none of which pass through CalendarEventWriter. A
 * recorder wired into write() would miss all three and every deletion besides,
 * which is precisely the log the docblock warns against. Doctrine already knows
 * what changed; asking it is the only way to be complete without asking every
 * future caller to remember.
 *
 * ── The ignore list is an ignore list, deliberately ───────────────────────
 *
 * IGNORED names the fields whose movement no client can observe, and everything
 * else is recorded. The inverse — listing the visible fields — reads better and
 * is wrong here: a field added later would default to invisible and go
 * unrecorded, silently, which is the failure mode with no symptom. This way a
 * new field defaults to being logged. The cost of over-recording is one wasted
 * conditional GET; the cost of under-recording is a client that never learns.
 *
 * ── Why the work is split across two events ───────────────────────────────
 *
 * onFlush is where Doctrine will still say what changed, and where a deletion
 * can still be read — a moment later its id and calendar are gone, and the
 * tombstone is the one row that has to survive it. But an insert has no id yet
 * at onFlush, because the database assigns it. So onFlush collects and
 * postFlush writes, by which time every id exists.
 *
 * ── Calendars, not only what is in them ───────────────────────────────────
 *
 * A row with no event is about the collection itself — created, renamed,
 * recoloured, removed. It is recorded here for the same reason and by the same
 * mechanism: Calendar has public mutable properties too, the settings UI writes
 * them directly, and a recorder somebody had to remember to call would be
 * exactly as incomplete.
 *
 * ── Why postFlush writes SQL rather than persisting entities ──────────────
 *
 * Because persisting and flushing from inside postFlush re-runs the whole unit
 * of work, not just the new rows — and the second pass sees whatever state the
 * first one left behind. EventDismisser is the case that proved it: it removes
 * an event while an EventSourceLink still points at it, which is legal for one
 * flush and explodes on a second as "a new entity was found through the
 * relationship EventSourceLink#event". Every calendar deletion in the suite
 * failed that way.
 *
 * A plain INSERT sidesteps all of it. These rows are append-only, never updated,
 * and never read back as managed objects in the request that wrote them, so the
 * identity map buys nothing here — and staying out of the UnitOfWork means this
 * listener cannot make somebody else's flush fail. CalendarChangeLog is still an
 * entity, because reading is where the mapping earns its keep.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class CalendarChangeLogListener
{
    /**
     * Fields whose movement is invisible to every calendar client: plMail's own
     * extraction metadata, the outbound-sync bookkeeping, the provider's ids,
     * and the timestamps every entity carries.
     */
    private const array IGNORED = [
        'createdAt',
        'updatedAt',
        'kind',
        'source',
        'confidence',
        'isUserEdited',
        'dedupKeyVersion',
        'remoteId',
        'remoteEtag',
        'remoteInstances',
        'syncState',
        'syncedAt',
    ];

    /**
     * The Calendar equivalent: sync state, push-channel plumbing and the
     * provider's own ids. What is left — name, colour, zone, order, the three
     * flags and the settings blob — is what a client draws.
     */
    private const array IGNORED_CALENDAR = [
        'createdAt',
        'updatedAt',
        'account',
        'integration',
        'remoteId',
        'syncToken',
        'lastSyncedAt',
        'lastSyncError',
        'syncFailureCount',
        'syncBackoffUntil',
        'syncFailureWasNews',
        'pushChannelId',
        'pushResourceId',
        'pushSecret',
        'pushExpiresAt',
    ];

    /**
     * Rows to write once the flush that produced them has finished.
     *
     * @var list<array{user:int,calendar:?int,collection:?Calendar,event:?int,entity:?CalendarEvent,uid:?string,kind:CalendarChangeKind}>
     */
    private array $pending = [];

    public function onFlush(OnFlushEventArgs $args): void
    {
        // Anything still queued belongs to a flush that never reached
        // postFlush — it threw. Those rows describe changes the database
        // rolled back, and writing them on the next successful flush would put
        // events into the log that no longer changed, which a client would
        // fetch and find unaltered. A worker whose EntityManager is reset and
        // carries on is exactly where that happens.
        $this->pending = [];

        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof CalendarEvent) {
                $this->queue($entity, CalendarChangeKind::Created, deferId: true);
            }

            if ($entity instanceof Calendar) {
                $this->queueCollection($entity, CalendarChangeKind::Created);
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof CalendarEvent) {
                $this->queueUpdate($entity, $uow->getEntityChangeSet($entity));
            }

            if ($entity instanceof Calendar) {
                $visible = array_diff(array_keys($uow->getEntityChangeSet($entity)), self::IGNORED_CALENDAR);

                if ([] !== $visible) {
                    $this->queueCollection($entity, CalendarChangeKind::Updated);
                }
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof CalendarEvent) {
                $this->queue($entity, CalendarChangeKind::Destroyed);
            }

            if ($entity instanceof Calendar) {
                $this->queueCollection($entity, CalendarChangeKind::Destroyed);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pending) {
            return;
        }

        $rows          = $this->pending;
        $this->pending = [];

        $connection = $args->getObjectManager()->getConnection();
        $now        = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($rows as $row) {
            // An insert's id was assigned by the flush that just finished, so
            // both the event's and a newly created calendar's are readable now.
            $eventId    = $row['event'] ?? $row['entity']?->id;
            $calendarId = $row['calendar'] ?? $row['collection']?->id;

            if (null === $calendarId) {
                continue;
            }

            // An event row whose id never arrived would be a change nobody
            // could fetch, so it is dropped; a collection row has no event by
            // definition and is complete without one. They are told apart by
            // whether an event was ever queued, not by what postFlush resolved.
            if (null === $eventId && null !== $row['uid']) {
                continue;
            }

            $connection->insert('calendar_change_log', [
                'user_id'     => $row['user'],
                'calendar_id' => $calendarId,
                'event_id'    => $eventId,
                'event_uid'   => $row['uid'],
                'change_kind' => $row['kind']->value,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    /**
     * An update, unless nothing a client could see actually moved.
     *
     * A calendar change is two rows rather than one. Each collection has its own
     * readers and its own token, and the collection an event left has to be told
     * the resource is gone — an "updated" row filed under the new calendar would
     * leave the old one advertising an href that now answers 404.
     *
     * @param array<string,mixed> $changeSet
     */
    private function queueUpdate(CalendarEvent $event, array $changeSet): void
    {
        $visible = array_diff(array_keys($changeSet), self::IGNORED);

        if ([] === $visible) {
            return;
        }

        if (true === in_array('calendar', $visible, true)) {
            /** @var array{0:mixed,1:mixed} $move */
            $move = $changeSet['calendar'];

            if ($move[0] instanceof Calendar) {
                $this->push($event, (int) $move[0]->id, CalendarChangeKind::Destroyed);
            }

            $this->queue($event, CalendarChangeKind::Created);

            return;
        }

        $this->queue($event, CalendarChangeKind::Updated);
    }

    /**
     * Queue a row for the event's current calendar.
     *
     * An event with no calendar sits in no collection, so no reader can see it
     * and there is nothing to tell them. Same for one with no owner: the log is
     * scoped by user, and a row that named nobody could never be read back.
     */
    private function queue(CalendarEvent $event, CalendarChangeKind $kind, bool $deferId = false): void
    {
        if (null === $event->calendar?->id) {
            return;
        }

        $this->push($event, (int) $event->calendar->id, $kind, $deferId);
    }

    /**
     * Queue a row about a calendar itself.
     *
     * The id is taken now whenever there is one, and deferred only for an
     * insert that has not got one yet. It cannot be deferred in every case,
     * which is what this did first: Doctrine clears an entity's identifier once
     * the delete has run, so by postFlush a removed calendar reports null and
     * the row that says it was removed is the one silently dropped. Exactly the
     * failure the event tombstone exists to avoid, in the other direction.
     */
    private function queueCollection(Calendar $calendar, CalendarChangeKind $kind): void
    {
        $userId = $calendar->usr?->id;

        if (null === $userId) {
            return;
        }

        $this->pending[] = [
            'user'       => $userId,
            'calendar'   => $calendar->id,
            'collection' => null === $calendar->id ? $calendar : null,
            'event'      => null,
            'entity'     => null,
            'uid'        => null,
            'kind'       => $kind,
        ];
    }

    private function push(CalendarEvent $event, int $calendarId, CalendarChangeKind $kind, bool $deferId = false): void
    {
        $userId = $event->usr?->id;

        if (null === $userId) {
            return;
        }

        $this->pending[] = [
            'user'       => $userId,
            'calendar'   => $calendarId,
            'collection' => null,
            'event'      => true === $deferId ? null : $event->id,
            'entity'     => true === $deferId ? $event : null,
            'uid'        => $event->uid,
            'kind'       => $kind,
        ];
    }
}
