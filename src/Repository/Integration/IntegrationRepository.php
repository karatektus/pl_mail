<?php

declare(strict_types=1);

namespace App\Repository\Integration;

use App\Domain\Enum\Integration\Capability;
use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\Integration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Integration>
 */
class IntegrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Integration::class);
    }

    /**
     * @return list<Integration>
     */
    public function findForUserOrdered(UserInterface $user): array
    {
        return $this->createQueryBuilder('integration')
            ->where('integration.usr = :usr')
            ->setParameter('usr', $user)
            ->orderBy('integration.provider', 'ASC')
            ->addOrderBy('integration.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Integration>
     */
    public function findActiveForUser(UserInterface $user): array
    {
        return $this->createQueryBuilder('integration')
            ->where('integration.usr = :usr')
            ->andWhere('integration.isActive = :active')
            ->setParameter('usr', $user)
            ->setParameter('active', true)
            ->orderBy('integration.provider', 'ASC')
            ->addOrderBy('integration.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Connections that can perform a given operation, for the menus that
     * offer it — the compose picker asks for Download, "Save to…" and the
     * filter action ask for Upload.
     *
     * Capability is a property of the Provider enum rather than a column, so
     * the filter happens in PHP over the user's own handful of rows. Encoding
     * it in SQL would mean a second source of truth that has to be migrated
     * every time a driver gains an ability.
     *
     * @return list<Integration>
     */
    public function findSupportingForUser(UserInterface $user, Capability $capability): array
    {
        return array_values(array_filter(
            $this->findActiveForUser($user),
            static fn (Integration $integration): bool => $integration->supports($capability),
        ));
    }

    public function findOneForUser(UserInterface $user, int $id): ?Integration
    {
        return $this->createQueryBuilder('integration')
            ->where('integration.usr = :usr')
            ->andWhere('integration.id = :id')
            ->setParameter('usr', $user)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByProviderForUser(UserInterface $user, Provider $provider): ?Integration
    {
        return $this->createQueryBuilder('integration')
            ->where('integration.usr = :usr')
            ->andWhere('integration.provider = :provider')
            ->setParameter('usr', $user)
            ->setParameter('provider', $provider)
            ->orderBy('integration.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * How many users have connected each provider, for the admin list.
     * Providers nobody has connected are absent rather than zero.
     *
     * @return array<string,int> provider backing value => distinct user count
     */
    public function countUsersByProvider(): array
    {
        /** @var list<array{provider:Provider,total:int|string}> $rows */
        $rows = $this->createQueryBuilder('integration')
            ->select('integration.provider AS provider', 'COUNT(DISTINCT integration.usr) AS total')
            ->groupBy('integration.provider')
            ->getQuery()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['provider']->value] = (int) $row['total'];
        }

        return $counts;
    }
}
