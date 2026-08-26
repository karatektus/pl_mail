<?php

declare(strict_types=1);

namespace App\Repository\Ai;

use App\Entity\Ai\AiSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiSettings>
 */
final class AiSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiSettings::class);
    }

    public function current(): ?AiSettings
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }

    /**
     * The settings as they stand, or a fresh set that is off.
     *
     * Never null, because every consumer would otherwise write the same
     * `?->enabledFor() ?? false` and one of them would get it wrong — and
     * getting it wrong means a feature running on an installation that never
     * turned it on.
     */
    public function currentOrDefault(): AiSettings
    {
        return $this->current() ?? new AiSettings();
    }
}
