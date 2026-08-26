<?php

declare(strict_types=1);

namespace App\Repository\Monitoring;

use App\Entity\Monitoring\LogSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LogSettings>
 */
final class LogSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LogSettings::class);
    }

    /** The one row, or null on an install that has never set a level. */
    public function current(): ?LogSettings
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }

    /**
     * The row an admin is about to edit, created on first sight so the form
     * always has something to bind to. Mirrors FcmConfigWriter::current().
     */
    public function currentOrNew(): LogSettings
    {
        return $this->current() ?? new LogSettings();
    }
}
