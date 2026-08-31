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
 * whole point. CalendarState's docblock rejects a partial log in the strongest
 * terms — "a log that recorded a quarter of the writes would be worse than
 * none… it is a lie with a number on it" — and then reaches for a recorder
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
     * Rows to write once the flush that produced them has finished.
     *
     * @var list<array{user:int,calendar:int,event:?int,entity:?CalendarEvent,uid:string,kind:CalendarChangeKind}>
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
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (false === $entity instanceof CalendarEvent) {
                continue;
            }

            $this->queueUpdate($entity, $uow->getEntityChangeSet($entity));
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof CalendarEvent) {
                $this->queue($entity, CalendarChangeKind::Destroyed);
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
            // An insert's id was assigned by the flush that just finished.
            $eventId = $row['event'] ?? $row['entity']?->id;

            if (null === $eventId) {
                continue;
            }

            $connection->insert('calendar_change_log', [
                'user_id'     => $row['user'],
                'calendar_id' => $row['calendar'],
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

    private function push(CalendarEvent $event, int $calendarId, CalendarChangeKind $kind, bool $deferId = false): void
    {
        $userId = $event->usr?->id;

        if (null === $userId) {
            return;
        }

        $this->pending[] = [
            'user'     => $userId,
            'calendar' => $calendarId,
            'event'    => true === $deferId ? null : $event->id,
            'entity'   => true === $deferId ? $event : null,
            'uid'      => $event->uid,
            'kind'     => $kind,
        ];
    }
}
