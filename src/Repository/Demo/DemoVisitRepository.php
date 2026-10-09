<?php

declare(strict_types=1);

namespace App\Repository\Demo;

use App\Entity\Demo\DemoVisit;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemoVisit>
 */
class DemoVisitRepository extends ServiceEntityRepository
{
    /**
     * How long a visit keeps its visitor hash. The longest window the panel
     * counts unique visitors over, and not a day more: past it the hash answers
     * no question anybody asks, so it is not kept.
     */
    public const int HASH_RETENTION_DAYS = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemoVisit::class);
    }

    /**
     * Sessions started since $since, and how many different visitors started
     * them.
     *
     * A visit without a hash is in the first number and not the second:
     * COUNT(DISTINCT) skips nulls, which is the honest reading of "we could not
     * tell who this was".
     *
     * @return array{sessions: int, visitors: int}
     */
    public function countSince(DateTimeImmutable $since): array
    {
        /** @var array{sessions: int|string, visitors: int|string} $row */
        $row = $this->createQueryBuilder('visit')
            ->select('COUNT(visit.id) AS sessions', 'COUNT(DISTINCT visit.visitorHash) AS visitors')
            ->where('visit.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleResult();

        return ['sessions' => (int) $row['sessions'], 'visitors' => (int) $row['visitors']];
    }

    /** Every session this instance has ever handed out. */
    public function countAll(): int
    {
        return $this->count([]);
    }

    /**
     * Takes the visitor hash off every visit older than the retention period,
     * and answers how many rows that was.
     *
     * The rows stay, because the all-time total is a count of them. One UPDATE,
     * run by app:demo:reap every ten minutes, so it finds a handful of rows or
     * none.
     */
    public function forgetVisitorsBefore(DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('visit')
            ->update()
            ->set('visit.visitorHash', ':none')
            ->where('visit.createdAt < :cutoff')
            ->andWhere('visit.visitorHash IS NOT NULL')
            ->setParameter('none', null)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
