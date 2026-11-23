<?php

declare(strict_types=1);

namespace App\Repository\Maintenance\BackfillRun;

use App\Domain\Enum\Job\JobState;
use App\Entity\Maintenance\BackfillRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reading the backfill ledger, for Admin → Backfills.
 *
 * Writes while a run is going go through BackfillRunProgressRepository; see
 * BackfillRun for why they cannot come through here.
 *
 * @extends ServiceEntityRepository<BackfillRun>
 */
class BackfillRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackfillRun::class);
    }

    /**
     * The newest runs, for the Recent runs card.
     *
     * @return list<BackfillRun>
     */
    public function recent(int $limit): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.requestedBy', 'u')
            ->addSelect('u')
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The latest run of every task that has one, by task name — the "last run"
     * line in the list and the detail.
     *
     * @return array<string, BackfillRun>
     */
    public function latestPerTask(): array
    {
        /** @var list<BackfillRun> $runs */
        $runs = $this->createQueryBuilder('r')
            ->where(sprintf(
                'r.id IN (SELECT MAX(r2.id) FROM %s r2 GROUP BY r2.taskName)',
                BackfillRun::class,
            ))
            ->getQuery()
            ->getResult();

        $byTask = [];

        foreach ($runs as $run) {
            $byTask[$run->taskName] = $run;
        }

        return $byTask;
    }

    /**
     * A run of this task that is queued or going, if there is one.
     *
     * The page refuses a second one while it is: two walks of the same table
     * at once take twice as long to arrive at the same rows, two of the tasks
     * do harm when run twice (see BackfillTaskInterface::isSafeToRunAgain()),
     * and the second Run click is nearly always somebody who did not see the
     * first one start.
     */
    public function activeForTask(string $taskName): ?BackfillRun
    {
        return $this->createQueryBuilder('r')
            ->where('r.taskName = :task')
            ->andWhere('r.state IN (:active)')
            ->setParameter('task', $taskName)
            ->setParameter('active', [JobState::Queued, JobState::Running])
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function hasActive(): bool
    {
        return null !== $this->createQueryBuilder('r')
            ->select('r.id')
            ->where('r.state IN (:active)')
            ->setParameter('active', [JobState::Queued, JobState::Running])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
