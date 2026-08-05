<?php

declare(strict_types=1);

namespace App\Repository\Calendar;

use App\Domain\Enum\Calendar\SyncState;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<CalendarEvent>
 */
class CalendarEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarEvent::class);
    }

    public function findOneForUser(UserInterface $user, int $id): ?CalendarEvent
    {
        return $this->findOneBy(['id' => $id, 'usr' => $user]);
    }

    /** The identity a later message updates or cancels against. */
    public function findOneByUid(Calendar $calendar, string $uid): ?CalendarEvent
    {
        return $this->findOneBy(['calendar' => $calendar, 'uid' => $uid]);
    }

    /**
     * Every row this user holds under one UID, in sidebar order.
     *
     * The plural of findOneByUid(), and the query the duplicate-meeting merge
     * rests on: a UID is unique within a calendar and deliberately not across
     * them, because one meeting reaching plMail by two honest routes — extracted
     * from its invitation, and mirrored from the provider — is two correct rows
     * carrying the organiser's one UID.
     *
     * Fetch-joins the calendar because every caller reads it immediately: the
     * editor to name and colour each copy, the resolver to ask whether it is
     * visible and whether it accepts writes. Ordered by the calendar's sidebar
     * position so the copies are listed in the order the user arranged them,
     * with the event id breaking ties rather than the database's whim.
     *
     * @return list<CalendarEvent>
     */
    public function findByUidForUser(UserInterface $user, string $uid): array
    {
        return $this->createQueryBuilder('event')
            ->addSelect('calendar')
            ->join('event.calendar', 'calendar')
            ->where('event.usr = :usr')
            ->andWhere('event.uid = :uid')
            ->setParameter('usr', $user)
            ->setParameter('uid', $uid)
            ->orderBy('calendar.sortOrder', 'ASC')
            ->addOrderBy('event.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The row a pulled change belongs to, matched on the remote's own id.
     *
     * Scoped to the calendar rather than looked up on remoteId alone: provider
     * ids are only unique within a calendar, and two calendars on one account
     * holding the same meeting is the normal case, not an edge one.
     */
    public function findOneByRemoteId(Calendar $calendar, string $remoteId): ?CalendarEvent
    {
        return $this->findOneBy(['calendar' => $calendar, 'remoteId' => $remoteId]);
    }

    /**
     * The series one of the remote's instance resources belongs to.
     *
     * The only question a bare tombstone can be asked. Microsoft reports a
     * cancelled occurrence as an id with nothing attached, and this is what
     * turns that id back into "one instance of this series is off" — without it
     * the id matches no row, the deletion does nothing, and the occurrence the
     * user removed in Outlook goes on being drawn.
     *
     * Raw DBAL because the test is jsonb key existence, which has no DQL
     * operator and no registered function. Written as jsonb_exists() rather than
     * the `?` operator that means the same thing, for the reason
     * MessageRepository gives: DBAL reads a bare `?` as a positional placeholder
     * and refuses the query. The id is fetched in SQL and the entity hydrated
     * through the ORM, so the caller gets a managed row it can write the
     * override onto — the same shape CalendarEventOccurrenceRepository uses for
     * its range query.
     *
     * Scoped to the calendar like every other identity lookup here: provider ids
     * are unique within a calendar and nowhere else.
     */
    public function findOneByRemoteInstanceId(Calendar $calendar, string $instanceId): ?CalendarEvent
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT e.id FROM calendar_event e
              WHERE e.calendar_id = :calendarId
                AND jsonb_exists(e.remote_instances, :instanceId)
              LIMIT 1',
            [
                'calendarId' => $calendar->id,
                'instanceId' => $instanceId,
            ],
            [
                'calendarId' => ParameterType::INTEGER,
                'instanceId' => ParameterType::STRING,
            ],
        );

        return false === $id ? null : $this->find((int) $id);
    }

    /**
     * Everything on this calendar that owes the remote a write, oldest first.
     *
     * Ordered by id so the pushes go out in the order the edits were made. It
     * matters for the one sequence that is not commutative: an event created
     * and then deleted before either left produces a PendingCreate row that is
     * already gone, and any other ordering would push the delete for a resource
     * the remote has not been told about yet.
     *
     * @return list<CalendarEvent>
     */
    public function findPendingSync(Calendar $calendar): array
    {
        return $this->findBy(
            ['calendar' => $calendar, 'syncState' => SyncState::pendingCases()],
            ['id' => 'ASC'],
        );
    }

    /**
     * Locally known rows on this calendar that a full read did not mention.
     *
     * The other half of a resync: a full read returns every live event and no
     * tombstones, so anything deleted at the remote while the sync token was
     * dead is learned only by its absence. Rows with no remoteId are excluded —
     * an event made here and not yet pushed has never been at the remote, and
     * "the remote did not mention it" says nothing about it.
     *
     * QueryBuilder because NOT IN over a possibly-empty list is not expressible
     * through findBy(), and because the empty case has to be a different query
     * rather than `NOT IN ()`, which is a syntax error in SQL.
     *
     * @param list<string> $seenRemoteIds
     *
     * @return list<CalendarEvent>
     */
    public function findRemoteRowsNotIn(Calendar $calendar, array $seenRemoteIds): array
    {
        $query = $this->createQueryBuilder('event')
            ->where('event.calendar = :calendar')
            ->andWhere('event.remoteId IS NOT NULL')
            ->setParameter('calendar', $calendar);

        if ([] !== $seenRemoteIds) {
            $query->andWhere('event.remoteId NOT IN (:seen)')
                ->setParameter('seen', $seenRemoteIds);
        }

        return $query->getQuery()->getResult();
    }

    /**
     * Rows on this calendar the remote never gave us.
     *
     * The exact complement of findRemoteRowsNotIn()'s population, and the one
     * question unsubscribing has to ask: everything with a remoteId is a copy
     * of something the provider still holds, so deleting it loses nothing,
     * while everything without one exists only here. That second set is what an
     * extracted booking looks like when the user has pointed
     * Account::SETTING_CALENDAR_TARGET at a mirrored calendar, and deleting it
     * with the subscription would destroy the only copy of a dinner
     * reservation because somebody unticked a calendar.
     *
     * @return list<CalendarEvent>
     */
    public function findRowsTheRemoteNeverGave(Calendar $calendar): array
    {
        return $this->createQueryBuilder('event')
            ->where('event.calendar = :calendar')
            ->andWhere('event.remoteId IS NULL')
            ->setParameter('calendar', $calendar)
            ->orderBy('event.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every event on one calendar, handed over one at a time.
     *
     * For the .ics export, which is the only reader here that has no bound on
     * how much it is about to touch: a person exporting a decade of a busy
     * calendar is asking for every row there is. findBy() would hydrate all of
     * them before the first byte reached the browser, so the response would
     * hold the whole calendar as entities *and* as text at once, on a request
     * whose size a user chooses.
     *
     * toIterable() hydrates one row per iteration instead, which pairs with
     * IcsExporter::document() yielding one event's worth of file at a time —
     * neither is much use without the other, and the peak stays at one meeting
     * however long the calendar is.
     *
     * Ordered by start rather than by id, because an exported file is read by
     * people as often as by programs and a calendar in chronological order is
     * the one they expect. The id breaks ties so the order is total, and a
     * re-export of an unchanged calendar therefore produces the same bytes —
     * which is what makes "diff two exports" a thing somebody can do.
     *
     * @return iterable<CalendarEvent>
     */
    public function iterateForCalendar(Calendar $calendar): iterable
    {
        return $this->createQueryBuilder('event')
            ->where('event.calendar = :calendar')
            ->setParameter('calendar', $calendar)
            ->orderBy('event.startsAt', 'ASC')
            ->addOrderBy('event.id', 'ASC')
            ->getQuery()
            ->toIterable();
    }

    /**
     * Recurring events whose materialised occurrences may not reach far enough
     * yet — everything unbounded, plus anything ending after the horizon the
     * last sweep wrote.
     *
     * QueryBuilder because that "plus" is an OR between a null test and a
     * comparison, and findBy() joins its criteria with AND.
     *
     * @return list<CalendarEvent>
     */
    public function findNeedingHorizonExtension(DateTimeImmutable $horizonEnd): array
    {
        return $this->createQueryBuilder('event')
            ->where('event.isRecurring = true')
            ->andWhere('event.recurrenceUntil IS NULL OR event.recurrenceUntil > :horizonEnd')
            ->setParameter('horizonEnd', $horizonEnd)
            ->orderBy('event.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
