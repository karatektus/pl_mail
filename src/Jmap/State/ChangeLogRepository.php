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
     * Prune rows older than $before for an account+objectType. Callers that
     * prune must accept that clients holding a state token below the new floor
     * will be told to resync (cannotCalculateChanges).
     */
    public function pruneOlderThan(int $accountId, JmapObjectType $type, \DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->where('c.accountId = :accountId')
            ->andWhere('c.objectType = :type')
            ->andWhere('c.createdAt < :before')
            ->setParameter('accountId', $accountId)
            ->setParameter('type', $type->value)
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
