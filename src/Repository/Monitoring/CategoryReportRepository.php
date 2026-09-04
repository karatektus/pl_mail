<?php

declare(strict_types=1);

namespace App\Repository\Monitoring;

use App\Entity\Monitoring\CategoryReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CategoryReport>
 *
 * Deliberately the same four methods as InsightReportRepository, with the same
 * names and the same argument. ReportedMailProvider reads both and merges them,
 * and it can only do that without a branch for each table if the two answer the
 * same questions the same way. Where they differ is a bug waiting to be found
 * in whichever half somebody was not looking at.
 */
final class CategoryReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CategoryReport::class);
    }

    /**
     * The panel's list: newest first, because the fresh ones are the ones a
     * rule might still be changed over.
     *
     * @return list<CategoryReport>
     */
    public function latest(int $limit = 100, bool $pendingOnly = false): array
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit);

        if (true === $pendingOnly) {
            $qb->andWhere('r.handledAt IS NULL');
        }

        /** @var list<CategoryReport> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * Everything the export writes, oldest first — the order the reports
     * actually arrived in, which is the order a corpus is read in.
     *
     * @return list<CategoryReport>
     */
    public function forExport(bool $pendingOnly = false): array
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.createdAt', 'ASC')
            ->addOrderBy('r.id', 'ASC');

        if (true === $pendingOnly) {
            $qb->andWhere('r.handledAt IS NULL');
        }

        /** @var list<CategoryReport> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    public function countPending(): int
    {
        return $this->countByHandled(false);
    }

    public function countHandled(): int
    {
        return $this->countByHandled(true);
    }

    private function countByHandled(bool $handled): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere(true === $handled ? 'r.handledAt IS NOT NULL' : 'r.handledAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
