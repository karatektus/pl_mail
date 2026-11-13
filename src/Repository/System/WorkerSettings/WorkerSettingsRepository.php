<?php

declare(strict_types=1);

namespace App\Repository\System\WorkerSettings;

use App\Entity\System\WorkerSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkerSettings>
 */
final class WorkerSettingsRepository extends ServiceEntityRepository
{
    /**
     * Four: enough that one hung provider leaves room for everyone else on a
     * household installation, few enough that four PHP processes of about
     * 50 MB each fit on the smallest NAS plMail is installed on.
     */
    public const int DEFAULT_CONCURRENCY = 4;

    public const int MIN_CONCURRENCY = 1;

    /**
     * Sixteen. Past that the database connections and the providers' own
     * per-user limits are the bottleneck, not this setting.
     */
    public const int MAX_CONCURRENCY = 16;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerSettings::class);
    }

    public function current(): ?WorkerSettings
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }

    public function currentOrNew(): WorkerSettings
    {
        return $this->current() ?? new WorkerSettings();
    }

    /**
     * The number in effect, for the worker that runs the jobs.
     *
     * Through the connection rather than the entity, because the caller is a
     * process that runs for an hour: an entity would be read once into the
     * identity map and never again, and a change on the admin page would not
     * reach the worker until its next restart.
     */
    public function concurrency(): int
    {
        $stored = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT mail_concurrency FROM worker_settings ORDER BY id ASC LIMIT 1',
        );

        return self::clamp(false === $stored || null === $stored ? null : (int) $stored);
    }

    /** What a stored value, or none, means. */
    public static function clamp(?int $value): int
    {
        if (null === $value) {
            return self::DEFAULT_CONCURRENCY;
        }

        return max(self::MIN_CONCURRENCY, min(self::MAX_CONCURRENCY, $value));
    }
}
