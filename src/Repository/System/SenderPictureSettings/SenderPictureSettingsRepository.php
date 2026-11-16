<?php

declare(strict_types=1);

namespace App\Repository\System\SenderPictureSettings;

use App\Entity\System\SenderPictureSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The administrator's switch for sender picture lookups (#639).
 *
 * @extends ServiceEntityRepository<SenderPictureSettings>
 */
class SenderPictureSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SenderPictureSettings::class);
    }

    public function current(): ?SenderPictureSettings
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }

    public function currentOrNew(): SenderPictureSettings
    {
        return $this->current() ?? new SenderPictureSettings();
    }

    /**
     * Whether lookups are allowed, read fresh every time.
     *
     * Through the connection rather than the entity for WorkerSettings'
     * reason: the ingest worker runs for an hour, an entity would be read
     * once into its identity map, and an administrator who switched lookups
     * OFF would watch the worker go on making requests until it restarted.
     * No row reads as off.
     */
    public function lookupsEnabled(): bool
    {
        $stored = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT lookups_enabled FROM sender_picture_settings ORDER BY id ASC LIMIT 1',
        );

        return true === $stored || 1 === $stored || 't' === $stored || '1' === $stored;
    }
}
