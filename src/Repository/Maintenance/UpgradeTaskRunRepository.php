<?php

declare(strict_types=1);

namespace App\Repository\Maintenance;

use App\Entity\Maintenance\UpgradeTaskRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UpgradeTaskRun>
 */
final class UpgradeTaskRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UpgradeTaskRun::class);
    }

    public function findByName(string $name): ?UpgradeTaskRun
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * Every row, newest attempt first — for the console listing and for
     * answering "what has this install already done?".
     *
     * @return list<UpgradeTaskRun>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.startedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
