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
     * Extracted events only, from now forward — the "Happening Soon" query.
     *
     * A kind is exactly what distinguishes an extracted event from one a person
     * typed, which is why that feature needs no table of its own.
     *
     * QueryBuilder for the window bounds, for `event.kind IS NOT NULL` on a
     * joined entity, and for the fetch-join — the widget renders each event and
     * its calendar, so lazy associations would be two queries per row.
     *
     * @param list<int> $calendarIds
     *
     * @return list<CalendarEventOccurrence>
     */
    public function findUpcomingExtracted(
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
            ->andWhere('event.kind IS NOT NULL')
            ->andWhere('occurrence.startsAt >= :from')
            ->andWhere('occurrence.startsAt < :to')
            ->setParameter('usr', $user)
            ->setParameter('calendarIds', $calendarIds)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('occurrence.startsAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
