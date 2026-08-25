<?php

declare(strict_types=1);

namespace App\Repository\Job;

use App\Domain\Enum\Job\JobState;
use App\Entity\Job\BackgroundJob;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<BackgroundJob>
 */
class BackgroundJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackgroundJob::class);
    }

    /**
     * This user's jobs worth showing: everything still going, plus anything
     * that FAILED recently.
     *
     * Success does not linger, and that is deliberate. A finished mark-as-read
     * has already changed the list the user is looking at, so an indicator
     * reporting it is telling them something they can see — while an indicator
     * that appears and disappears on its own is a topbar that reflows for no
     * reason, which is exactly the kind of thing that makes a page feel
     * unsteady.
     *
     * A failure is the opposite: nothing changed, and if this does not say so
     * nothing does. So it stays long enough to be read.
     *
     * @return list<BackgroundJob>
     */
    public function findVisibleForUser(UserInterface $user, int $failureWindowSeconds = 300): array
    {
        return $this->createQueryBuilder('job')
            ->where('job.usr = :user')
            ->andWhere('job.state IN (:active) OR (job.state = :failed AND job.finishedAt > :since)')
            ->setParameter('user', $user)
            ->setParameter('active', [JobState::Queued, JobState::Running])
            ->setParameter('failed', JobState::Failed)
            ->setParameter('since', new DateTimeImmutable(sprintf('-%d seconds', $failureWindowSeconds)))
            ->orderBy('job.createdAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
    }

    /**
     * Jobs old enough that nobody is waiting on them.
     *
     * @return list<BackgroundJob>
     */
    public function findFinishedBefore(DateTimeImmutable $before, int $limit = 500): array
    {
        return $this->createQueryBuilder('job')
            ->where('job.finishedAt IS NOT NULL')
            ->andWhere('job.finishedAt < :before')
            ->setParameter('before', $before)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
