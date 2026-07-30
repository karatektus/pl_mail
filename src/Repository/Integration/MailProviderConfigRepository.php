<?php

declare(strict_types=1);

namespace App\Repository\Integration;

use App\Domain\Enum\Account\MailProvider;
use App\Entity\Integration\MailProviderConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailProviderConfig>
 */
class MailProviderConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailProviderConfig::class);
    }

    public function findOneByProvider(MailProvider $provider): ?MailProviderConfig
    {
        return $this->findOneBy(['provider' => $provider]);
    }

    /**
     * Every configured provider keyed by its backing value, so a caller
     * iterating MailProvider::cases() needs one query rather than one per case.
     *
     * @return array<string,MailProviderConfig>
     */
    /**
     * How many providers are fully registered.
     *
     * findAll() plus the entity's own isComplete(), rather than a query with
     * two IS NOT NULL clauses: "complete" is defined on the entity, there are
     * two rows at most, and a copy of that definition in DQL would be one more
     * thing to keep in step.
     */
    public function countComplete(): int
    {
        return count(array_filter(
            $this->findAll(),
            static fn (MailProviderConfig $config): bool => $config->isComplete(),
        ));
    }

    public function findAllIndexedByProvider(): array
    {
        $indexed = [];

        foreach ($this->findAll() as $config) {
            $indexed[$config->provider->value] = $config;
        }

        return $indexed;
    }
}
