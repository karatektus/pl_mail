<?php

declare(strict_types=1);

namespace App\Repository\Oidc;

use App\Entity\Oidc\OidcConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OidcConfig>
 */
class OidcConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OidcConfig::class);
    }

    /**
     * The single sign-on configuration an administrator saved, or null when
     * nobody has. Ordered by id for the reason FcmConfigRepository::current()
     * gives.
     */
    public function current(): ?OidcConfig
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
