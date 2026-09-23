<?php

declare(strict_types=1);

namespace App\Jmap\State;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChangeLog>
 */
final class ChangeLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChangeLog::class);
    }

    /**
     * The current state token for an account+objectType: the highest sequence
     * recorded, or 0 when nothing has ever been logged for it.
     *
     * MAX over one column, which Doctrine's API has no form of. The alternative
     * is loading an account's entire change history to read the largest of one
     * integer — on every JMAP request, since a state token is on every reply.
     */
    public function latestSequence(int $accountId, JmapObjectType $type): int
    {
        $result = $this->createQueryBuilder('c')
            ->select('MAX(c.sequence)')
            ->where('c.accountId = :accountId')
            ->andWhere('c.objectType = :type')
            ->setParameter('accountId', $accountId)
            ->setParameter('type', $type->value)
            ->getQuery()
            ->getSingleScalarResult();

        if (null === $result) {
            return 0;
        }

        return (int) $result;
    }

    /**
     * The lowest sequence still retained for an account+objectType, or 0 when
     * none remain. Used to detect a state token that predates pruned history.
     *
     * MIN over one column, for the same reason latestSequence() uses MAX.
     */
    public function oldestSequence(int $accountId, JmapObjectType $type): int
    {
        $result = $this->createQueryBuilder('c')
            ->select('MIN(c.sequence)')
            ->where('c.accountId = :accountId')
            ->andWhere('c.objectType = :type')
            ->setParameter('accountId', $accountId)
            ->setParameter('type', $type->value)
            ->getQuery()
            ->getSingleScalarResult();

        if (null === $result) {
            return 0;
        }

        return (int) $result;
    }

    /**
     * Rows strictly newer than $since for an account+objectType, ordered by
     * sequence ascending. Fetches one more than $limit so the caller can tell
     * whether more changes remain beyond the returned window.
     *
     * QueryBuilder for `sequence > :since`: findBy() compares a field to a
     * value, and a delta is by definition a range.
     *
     * @return list<ChangeLog>
     */
    public function changesSince(int $accountId, JmapObjectType $type, int $since, int $limit): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.accountId = :accountId')
            ->andWhere('c.objectType = :type')
            ->andWhere('c.sequence > :since')
            ->setParameter('accountId', $accountId)
            ->setParameter('type', $type->value)
            ->setParameter('since', $since)
            ->orderBy('c.sequence', 'ASC')
            ->setMaxResults($limit + 1)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retention for the whole log: drop rows older than $before, keeping the
     * newest row of every account+objectType however old it is.
     *
     * The kept row is what stops a state token going backwards. The token is
     * the highest sequence present, so pruning a quiet type — a Mailbox tree
     * nobody has touched in months — down to nothing would reset its state to
     * "0" and tell every client holding the real one that it is ahead of the
     * log. With it kept, a client at the current state still gets "no
     * changes", and one below the new floor gets cannotCalculateChanges from
     * StateManager::changesSince() and resyncs, which is the price of
     * retention and the answer RFC 8620 §5.2 provides for it.
     *
     * A bulk DELETE because retention that hydrated its victims would scale
     * with the history it exists to remove. The "newest per group" test is a
     * correlated MAX, which idx_jmap_change_scan answers with one index probe
     * per row rather than a grouping of the whole table. Raw SQL for that
     * correlation: DQL renders a DELETE without a table alias, so the outer
     * row's columns come out unqualified inside the subquery and bind to the
     * subquery's own table — every row would be compared with itself.
     */
    public function pruneOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                DELETE FROM jmap_change_log c
                 WHERE c.created_at < :before
                   AND c.sequence < (
                       SELECT MAX(n.sequence) FROM jmap_change_log n
                        WHERE n.account_id = c.account_id AND n.object_type = c.object_type
                   )
                SQL,
            ['before' => $before->format('Y-m-d H:i:s')],
        );
    }
}
