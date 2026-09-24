<?php

declare(strict_types=1);

namespace App\Repository\System;

use App\Entity\System\UpdateCheck;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UpdateCheck>
 */
class UpdateCheckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UpdateCheck::class);
    }

    /** The one row, or null on an installation that has never checked or chosen. */
    public function current(): ?UpdateCheck
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }

    /** The row to write to, created on first sight. Mirrors LogSettingsRepository. */
    public function currentOrNew(): UpdateCheck
    {
        return $this->current() ?? new UpdateCheck();
    }
}
