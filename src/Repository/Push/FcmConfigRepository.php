<?php

declare(strict_types=1);

namespace App\Repository\Push;

use App\Entity\Push\FcmConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FcmConfig>
 */
class FcmConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FcmConfig::class);
    }

    /**
     * The installation's Firebase configuration, or null when an admin has
     * never opened the page.
     *
     * Ordered by id despite the unique index guaranteeing one row: findOneBy()
     * with no ordering answers whichever row the planner reaches first, and if
     * the index were ever dropped this would become non-deterministic in a way
     * that shows up as push working on some requests.
     */
    public function current(): ?FcmConfig
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
