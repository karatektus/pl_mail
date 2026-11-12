<?php

declare(strict_types=1);

namespace App\Repository\Integration\TrustedHost;

use App\Entity\Integration\TrustedHost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Not final: IntegrationUrlValidatorTest doubles it, so the SSRF rules can be
 * tested without a database behind them.
 *
 * @extends ServiceEntityRepository<TrustedHost>
 */
class TrustedHostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrustedHost::class);
    }

    /**
     * The row for a host, which the caller has already normalised
     * (TrustedHostList::normalise()).
     *
     * Asked on every request to a user-typed address rather than read once and
     * kept: the workers are long-running processes, and a host an administrator
     * removed must stop being reachable on the next request, not at the next
     * restart.
     */
    public function findOneByHost(string $host): ?TrustedHost
    {
        return $this->findOneBy(['host' => $host]);
    }

    /**
     * Every host, alphabetically — the admin list and the config backup.
     *
     * @return list<TrustedHost>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['host' => 'ASC']);
    }
}
