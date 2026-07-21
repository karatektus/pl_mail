<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProcessHeartbeat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProcessHeartbeatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessHeartbeat::class);
    }

    /**
     * @return list<ProcessHeartbeat>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('h')
            ->orderBy('h.type', 'ASC')
            ->addOrderBy('h.key', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
