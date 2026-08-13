<?php

declare(strict_types=1);

namespace App\Repository\Calendar;

use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<CalendarEventOccurrence>
 */
class CalendarEventOccurrenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarEventOccurrence::class);
    }

    /**
     * Everything overlapping a window — the query every calendar view makes.
     *
     * Raw DBAL for the id lookup because the test is `span && tsrange(...)`,
     * and DQL has no range overlap operator: `&&` is not expressible, and there
     * is no function to register that would make it so. Doctrine's own API
     * cannot reach a GiST index at all.
     *
     * The naive DQL alternative — `starts_at < :to AND ends_at > :from` — is
     * not merely slower, it degrades as the calendar gets more interesting. A
     * btree on starts_at stops approximating ends_at ordering the moment
     * multi-day events exist, so the planner scans everything from the window's
     * start backwards to find the ones that began earlier and are still
     * running. The overlap operator answers that from one index.
     *
     * Ids are fetched in SQL and hydrated through the ORM, so callers still get
     * entities with their events attached, and the N+1 stays fetch-joined here
     * rather than in every view.
     *
     * @param list<int> $calendarIds
     *
     * @return list<CalendarEventOccurrence>
     */
    public function findInRange(
        UserInterface     $user,
        array             $calendarIds,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        bool              $includeCancelled = false,
    ): array {
        if (0 === count($calendarIds)) {
            return [];
        }

        $sql = 'SELECT o.id
                FROM calendar_event_occurrence o
                WHERE o.usr_id = :occUserId
                  AND o.calendar_id IN (:occCalendarIds)
                  AND o.span && tsrange(:occFrom, :occTo, \'[)\')';

        if (false === $includeCancelled) {
            $sql .= ' AND o.cancelled = false';
        }

        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            $sql,
            [
                'occUserId'      => $user->id,
                'occCalendarIds' => $calendarIds,
                'occFrom'        => $from->format('Y-m-d H:i:s'),
                'occTo'          => $to->format('Y-m-d H:i:s'),
            ],
            [
                'occUserId'      => ParameterType::INTEGER,
                'occCalendarIds' => ArrayParameterType::INTEGER,
                'occFrom'        => ParameterType::STRING,
                'occTo'          => ParameterType::STRING,
            ],
        );

        if (0 === count($ids)) {
            return [];
        }

        return $this->createQueryBuilder('occurrence')
            ->addSelect('event', 'calendar')
            ->join('occurrence.event', 'event')
            ->join('occurrence.calendar', 'calendar')
            ->where('occurrence.id IN (:ids)')
            ->setParameter('ids', array_map('intval', $ids))
            ->orderBy('occurrence.startsAt', 'ASC')
            ->addOrderBy('occurrence.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything starting inside a window, soonest first — the "Happening Soon"
     * query.
     *
     * It used to carry `event.kind IS NOT NULL`, which listed extracted events
     * and nothing else. That was wrong for the panel it feeds: a person who
     * types "dentist, Thursday" has said the same thing about Thursday that a
     * booking confirmation says, and a glance surface that answers "what is
     * coming up?" with everything *except* what the owner put there themselves
     * is a surface nobody can trust. The kind still distinguishes the two —
     * HappeningSoonRow keeps it, nullable, and the row wears its icon when there
     * is one — it just no longer decides who is listed.
     *
     * QueryBuilder for the window bounds and for the fetch-join — the widget
     * renders each event and its calendar, so lazy associations would be two
     * queries per row.
     *
     * @param list<int> $calendarIds
     *
     * @return list<CalendarEventOccurrence>
     */
    public function findUpcoming(
        UserInterface     $user,
        array             $calendarIds,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int               $limit = 12,
    ): array {
        if (0 === count($calendarIds)) {
            return [];
        }

        return $this->createQueryBuilder('occurrence')
            ->addSelect('event', 'calendar')
            ->join('occurrence.event', 'event')
            ->join('occurrence.calendar', 'calendar')
            ->where('occurrence.usr = :usr')
            ->andWhere('occurrence.calendar IN (:calendarIds)')
            ->andWhere('occurrence.cancelled = false')
            ->andWhere('occurrence.startsAt >= :from')
            ->andWhere('occurrence.startsAt < :to')
            ->setParameter('usr', $user)
            ->setParameter('calendarIds', $calendarIds)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('occurrence.startsAt', 'ASC')
            // The tiebreaker is not decoration, and it is the same one
            // findInRange() above already carries. A meeting held on two
            // calendars is two occurrences at the SAME instant, so ordering on
            // startsAt alone leaves them tied and Postgres free to return them
            // in whatever order the plan happens to produce. EventClusterer
            // preserves that order into the cluster, so the merged row's label
            // read "On Account, Mirror" on one run and "On Mirror, Account" on
            // the next — from the same rows, with nothing having changed. It
            // also decides which member is the cluster's PRIMARY, which is the
            // event a click on the row opens.
            //
            // It surfaced as an intermittent failure in HappeningSoonReaderTest
            // and HappeningSoonPanelTest, which is the same defect seen from the
            // suite's side: whether they passed depended on the physical row
            // layout the rest of the run happened to leave behind.
            ->addOrderBy('occurrence.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Occurrences whose event carries alerts and whose start is near enough to
     * now that one of those alerts might be due.
     *
     * A candidate list, not an answer: which alert is actually due depends on
     * each alert's own offset, and that lives in jsonb where SQL cannot do
     * arithmetic on it. So the window here is the widest one any honoured offset
     * could reach — see DueAlertReader, which owns both bounds and does the
     * deciding — and the sweep discards most of what this returns.
     *
     * Raw DBAL for the id lookup, for one reason the ORM cannot cover: jsonb key
     * existence has no DQL operator and no registered function. Written as
     * `jsonb_exists()` rather than the `?` operator that means the same thing —
     * DBAL reads a bare `?` as a positional placeholder and refuses the query.
     * The same two-step CalendarEventRepository and findInRange() above use:
     * ids in SQL, entities through the ORM, so callers get the event and the
     * owner fetch-joined rather than two queries per notification.
     *
     * Cancelled occurrences and cancelled events are excluded in SQL rather than
     * in PHP. A meeting that was called off must not send a reminder, and the
     * two are separate states — one instance struck through, or the whole series
     * — so both are asked about.
     *
     * @return list<CalendarEventOccurrence>
     */
    public function findAlertCandidates(DateTimeImmutable $from, DateTimeImmutable $to, int $limit): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT o.id
                FROM calendar_event_occurrence o
                JOIN calendar_event e ON e.id = o.event_id
                WHERE o.cancelled = false
                  AND o.starts_at >= :alertFrom
                  AND o.starts_at <= :alertTo
                  AND e.status <> 'cancelled'
                  AND jsonb_exists(e.jscalendar, 'alerts')
                ORDER BY o.starts_at ASC
                LIMIT :alertLimit
                SQL,
            [
                'alertFrom'  => $from->format('Y-m-d H:i:s'),
                'alertTo'    => $to->format('Y-m-d H:i:s'),
                'alertLimit' => $limit,
            ],
            [
                'alertFrom'  => ParameterType::STRING,
                'alertTo'    => ParameterType::STRING,
                'alertLimit' => ParameterType::INTEGER,
            ],
        );

        if (0 === count($ids)) {
            return [];
        }

        return $this->createQueryBuilder('occurrence')
            ->addSelect('event', 'owner')
            ->join('occurrence.event', 'event')
            ->join('occurrence.usr', 'owner')
            ->where('occurrence.id IN (:ids)')
            ->setParameter('ids', array_map('intval', $ids))
            ->orderBy('occurrence.startsAt', 'ASC')
            ->addOrderBy('occurrence.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * One named instance of one series — the row a chip was rendered from.
     *
     * Looked up by recurrence id rather than by start, because those are not the
     * same column once somebody has moved an instance: the recurrence id is
     * where the rule put it and the start is where it went. Naming it by its
     * start would find nothing the second time the same instance is edited,
     * which is exactly the case that must keep working.
     *
     * A query rather than a walk over CalendarEvent::$occurrences: a daily
     * series over the materialiser's horizon is a thousand rows, and hydrating
     * all of them to find one is a page load nobody would connect to the click
     * that caused it.
     *
     * The recurrence id must be UTC — Doctrine writes a datetime parameter in
     * whatever zone the object carries, and the column is UTC, so an instant
     * handed in as local time silently matches nothing.
     */
    public function findOneByRecurrence(CalendarEvent $event, DateTimeImmutable $recurrenceId): ?CalendarEventOccurrence
    {
        return $this->findOneBy([
            'event'        => $event,
            'recurrenceId' => $recurrenceId,
        ]);
    }

    /**
     * Drops an event's materialised rows so the materialiser can write the new
     * set. One statement rather than loading a collection to delete it: a daily
     * event over the horizon is well over a thousand rows, and none of them
     * have behaviour worth hydrating.
     */
    public function deleteForEvent(CalendarEvent $event): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM calendar_event_occurrence WHERE event_id = :eventId',
            ['eventId' => $event->id],
            ['eventId' => ParameterType::INTEGER],
        );
    }
}
