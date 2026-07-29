<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Enum\Integration\Provider;
use App\Entity\IntegrationProviderConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IntegrationProviderConfig>
 */
class IntegrationProviderConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IntegrationProviderConfig::class);
    }

    /**
     * Every configured provider keyed by its backing value, so callers that
     * iterate Provider::cases() can look each one up without a query per case.
     * Missing keys mean "never configured", which reads as disabled.
     *
     * @return array<string,IntegrationProviderConfig>
     */
    public function findAllIndexedByProvider(): array
    {
        $indexed = [];

        foreach ($this->findAll() as $config) {
            $indexed[$config->provider->value] = $config;
        }

        return $indexed;
    }

    public function findOneByProvider(Provider $provider): ?IntegrationProviderConfig
    {
        return $this->findOneBy(['provider' => $provider]);
    }

    /**
     * Configs a user could actually connect to right now. Providers without a
     * driver are excluded here rather than in the template, so no caller can
     * accidentally offer a stub as connectable.
     *
     * @return list<IntegrationProviderConfig>
     */
    public function findConnectable(): array
    {
        return array_values(array_filter(
            $this->findBy(['isEnabled' => true]),
            static fn (IntegrationProviderConfig $config): bool => $config->isConnectable(),
        ));
    }
}
