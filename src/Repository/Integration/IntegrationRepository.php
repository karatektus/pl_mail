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
        return $this->findBy(['usr' => $user], ['provider' => 'ASC', 'name' => 'ASC']);
    }

    /**
     * @return list<Integration>
     */
    public function findActiveForUser(UserInterface $user): array
    {
        return $this->findBy(
            ['usr' => $user, 'isActive' => true],
            ['provider' => 'ASC', 'name' => 'ASC'],
        );
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

    /** Scoped to the owner, so another user's id can never resolve. */
    public function findOneForUser(UserInterface $user, int $id): ?Integration
    {
        return $this->findOneBy(['usr' => $user, 'id' => $id]);
    }

    /**
     * The oldest connection of a provider. Ordered rather than arbitrary: a
     * user may have connected the same provider twice, and "the one that has
     * been there longest" is at least a stable answer.
     */
    public function findOneByProviderForUser(UserInterface $user, Provider $provider): ?Integration
    {
        return $this->findOneBy(['usr' => $user, 'provider' => $provider], ['id' => 'ASC']);
    }

    /**
     * How many users have connected each provider, for the admin list.
     * Providers nobody has connected are absent rather than zero.
     *
     * A COUNT DISTINCT grouped by provider — an aggregate, which Doctrine's API
     * has no form of: count() answers one number about one criteria set, so
     * this would otherwise be a query per provider on every admin page load.
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
