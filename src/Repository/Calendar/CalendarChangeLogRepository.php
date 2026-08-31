<?php

declare(strict_types=1);

namespace App\Repository\Calendar;

use App\Entity\Calendar\CalendarChangeLog;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reading the calendar change log, from the two directions that read it.
 *
 * CalDAV asks about one collection and JMAP asks about every calendar a user
 * has, so each question comes in a per-calendar and a per-user form. They differ
 * only in which column is filtered — the ordering, the bounds and the
 * "fetch one extra to detect more" trick are the same — so the bodies are one
 * private builder and the public methods exist to name the two scopes. A single
 * method taking a column name would put the choice at the call site, where a
 * typo is a silent full-table read rather than a missing method.
 *
 * @extends ServiceEntityRepository<CalendarChangeLog>
 */
final class CalendarChangeLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarChangeLog::class);
    }

    /** The CalDAV sync-token for one collection: 0 when nothing is logged. */
    public function latestSequenceForCalendar(int $calendarId): int
    {
        return $this->boundary('calendarId', $calendarId, 'MAX');
    }

    /** The JMAP state token for a user's calendars: 0 when nothing is logged. */
    public function latestSequenceForUser(int $userId): int
    {
        return $this->boundary('userId', $userId, 'MAX');
    }

    /**
     * The lowest sequence still retained, or 0 when none remain.
     *
     * A reader holding a token below this has fallen off the back of pruned
     * history and must be told to start over — `cannotCalculateChanges` in JMAP,
     * an invalid sync-token in CalDAV, which RFC 6578 answers with a `403` and
     * `valid-sync-token`, and the client then does a full REPORT.
     */
    public function oldestSequenceForCalendar(int $calendarId): int
    {
        return $this->boundary('calendarId', $calendarId, 'MIN');
    }

    public function oldestSequenceForUser(int $userId): int
    {
        return $this->boundary('userId', $userId, 'MIN');
    }

    /**
     * Rows strictly newer than $since for one collection, oldest first.
     *
     * Fetches one more than $limit so the caller can tell whether more remain
     * without counting the whole tail.
     *
     * @return list<CalendarChangeLog>
     */
    public function changesSinceForCalendar(int $calendarId, int $since, int $limit): array
    {
        return $this->delta('calendarId', $calendarId, $since, $limit);
    }

    /** @return list<CalendarChangeLog> */
    public function changesSinceForUser(int $userId, int $since, int $limit): array
    {
        return $this->delta('userId', $userId, $since, $limit);
    }

    /** The JMAP state string for a user's calendars themselves. */
    public function latestCollectionSequenceForUser(int $userId): int
    {
        return $this->aggregate($this->collections($userId), 'MAX');
    }

    public function oldestCollectionSequenceForUser(int $userId): int
    {
        return $this->aggregate($this->collections($userId), 'MIN');
    }

    /**
     * Collection changes strictly newer than $since, oldest first.
     *
     * @return list<CalendarChangeLog>
     */
    public function collectionChangesSinceForUser(int $userId, int $since, int $limit): array
    {
        /** @var list<CalendarChangeLog> $rows */
        $rows = $this->collections($userId)
            ->andWhere('c.sequence > :since')
            ->setParameter('since', $since)
            ->orderBy('c.sequence', 'ASC')
            ->setMaxResults($limit + 1)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The newest sequence recorded for each event in one collection.
     *
     * Feeds DavEtag, so that a resource's ETag and the collection's sync-token
     * come from the same number and cannot disagree about whether something
     * changed. One grouped query rather than one per resource: a PROPFIND on a
     * collection asks for every ETag at once, and a year of events would
     * otherwise be a year of round trips.
     *
     * Events with no row are simply absent from the result — the caller falls
     * back, and an isset() check is cheaper than materialising a null per event.
     *
     * @return array<int,int> event id => highest sequence
     */
    public function latestSequencesForCalendar(int $calendarId): array
    {
        /** @var list<array{eventId:int,seq:string|int}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.eventId AS eventId', 'MAX(c.sequence) AS seq')
            ->where('c.calendarId = :id')
            ->andWhere('c.eventId IS NOT NULL')
            ->setParameter('id', $calendarId)
            ->groupBy('c.eventId')
            ->getQuery()
            ->getResult();

        $sequences = [];

        foreach ($rows as $row) {
            $sequences[(int) $row['eventId']] = (int) $row['seq'];
        }

        return $sequences;
    }

    /**
     * Prune rows older than $before for one collection.
     *
     * A bulk DELETE, like ChangeLogRepository::pruneOlderThan(): retention that
     * hydrated its victims would scale with the history it exists to remove.
     * Nothing in src/ calls this yet — it is here so the ceiling in
     * CalendarChangeLog's docblock has an answer when it is reached.
     */
    public function pruneOlderThan(int $calendarId, DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->where('c.calendarId = :id')
            ->andWhere('c.createdAt < :before')
            ->setParameter('id', $calendarId)
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    /**
     * MAX or MIN over the sequence within one scope.
     *
     * Doctrine's API has no form of an aggregate over one column, and the
     * alternative is loading an entire change history to read the largest of one
     * integer — on every request, since a state token is on every reply.
     */
    private function boundary(string $field, int $id, string $aggregate): int
    {
        return $this->aggregate($this->scoped($field, $id), $aggregate);
    }

    private function aggregate(QueryBuilder $builder, string $aggregate): int
    {
        $result = $builder
            ->select(sprintf('%s(c.sequence)', $aggregate))
            ->getQuery()
            ->getSingleScalarResult();

        if (null === $result) {
            return 0;
        }

        return (int) $result;
    }

    /**
     * @return list<CalendarChangeLog>
     */
    private function delta(string $field, int $id, int $since, int $limit): array
    {
        return $this->scoped($field, $id)
            ->andWhere('c.sequence > :since')
            ->setParameter('since', $since)
            ->orderBy('c.sequence', 'ASC')
            ->setMaxResults($limit + 1)
            ->getQuery()
            ->getResult();
    }

    /**
     * Rows in one scope that are about events.
     *
     * The event filter is here rather than at each call site because forgetting
     * it is not a wrong number — it is a renamed calendar reported to a client
     * as a changed event, whose href would then be built from a null UID.
     */
    private function scoped(string $field, int $id): QueryBuilder
    {
        return $this->events($field, $id);
    }

    private function events(string $field, int $id): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->where(sprintf('c.%s = :id', $field))
            ->andWhere('c.eventId IS NOT NULL')
            ->setParameter('id', $id);
    }

    /** The mirror of events(): rows about the collections themselves. */
    private function collections(int $userId): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->where('c.userId = :id')
            ->andWhere('c.eventId IS NULL')
            ->setParameter('id', $userId);
    }
}
