<?php

declare(strict_types=1);

namespace App\Repository\Monitoring;

use App\Entity\Monitoring\CategoryReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CategoryReport>
 */
final class CategoryReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CategoryReport::class);
    }

    /**
     * The newest reports, for the admin panel.
     *
     * Bounded rather than paged: this is a list somebody reads in one sitting
     * to decide whether a rule should change, and a hundred is already more
     * examples than that decision needs. If it ever fills up, the answer is to
     * act on them and clear it, which is the button beside the list.
     *
     * @return list<CategoryReport>
     */
    public function recent(int $limit = 100): array
    {
        /** @var list<CategoryReport> $rows */
        $rows = $this->createQueryBuilder('r')
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Everything, for the "we have acted on these" button. */
    public function clearAll(): int
    {
        return (int) $this->createQueryBuilder('r')->delete()->getQuery()->execute();
    }
}
