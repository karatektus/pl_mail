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
    /**
     * How long an active job may go without moving before it counts as
     * abandoned rather than slow, in seconds.
     *
     * Fifteen minutes, and generous deliberately. A chunk is a hundred
     * conversations and each of them can carry a provider round trip, so a slow
     * IMAP host makes an entirely healthy chunk take minutes. The two ways to
     * be wrong here are not symmetrical: too long and a dead job lingers in the
     * indicator for another quarter of an hour, too short and live work is
     * declared failed while it is still running — and the user is told nothing
     * happened when in fact it did.
     *
     * SECONDS, like the failure window beside it in findVisibleForUser. One
     * class measuring two windows in two units is how a sixty-times mistake
     * gets made.
     */
    public const int DEFAULT_STALE_SECONDS = 900;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackgroundJob::class);
    }

    /**
     * This user's jobs worth showing: everything still MOVING, plus anything
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
     * WHY THE ACTIVE ARM IS BOUNDED BY TIME AT ALL
     *
     * It was not, and a job in `running` was therefore visible forever. Three
     * "Marking as read" jobs stopped at 1400/1770, 600/1180 and 100/1275 —
     * workers killed mid-flight, so finish() was never reached and no state on
     * the row ever changed again — and the indicator went on reporting them as
     * running for weeks. There was nothing the user could press, and nothing
     * the application could have done about it either: "still going" and
     * "abandoned" were the same row.
     *
     * lastProgressAt is what separates them, and this is where the separation
     * has to happen. The reaper (app:jobs:reap) turns such a job into a real
     * failure so the user is told once, but the reaper runs every few minutes
     * while a page renders now — so the read has to be able to answer correctly
     * on its own, before anything has been swept.
     *
     * The two windows are different things and are passed separately: the
     * failure window is how long a person gets to READ bad news, the staleness
     * window is how long a worker may go quiet before we stop believing it.
     *
     * @return list<BackgroundJob>
     */
    public function findVisibleForUser(
        UserInterface $user,
        int $failureWindowSeconds = 300,
        int $staleAfterSeconds = self::DEFAULT_STALE_SECONDS,
    ): array {
        return $this->createQueryBuilder('job')
            ->where('job.usr = :user')
            ->andWhere('(job.state IN (:active) AND job.lastProgressAt > :moving) OR (job.state = :failed AND job.finishedAt > :since)')
            ->setParameter('user', $user)
            ->setParameter('active', [JobState::Queued, JobState::Running])
            ->setParameter('failed', JobState::Failed)
            ->setParameter('moving', new DateTimeImmutable(sprintf('-%d seconds', $staleAfterSeconds)))
            ->setParameter('since', new DateTimeImmutable(sprintf('-%d seconds', $failureWindowSeconds)))
            ->orderBy('job.createdAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
    }

    /**
     * Jobs that claim to be active and have stopped moving — across every user.
     *
     * The exact complement of the active arm above: that one keeps rows whose
     * stamp is strictly NEWER than the cutoff, this one takes the rest. Written
     * as `<=` against the same instant on purpose, so a job cannot fall through
     * the gap between the two and be neither shown nor swept.
     *
     * Not scoped to a user, because nobody asks this question on a user's
     * behalf — app:jobs:reap sweeps the table, and the mess it cleans up was
     * made by a worker dying, which is not a per-user event.
     *
     * @return list<BackgroundJob>
     */
    public function findStranded(DateTimeImmutable $before, int $limit = 200): array
    {
        return $this->createQueryBuilder('job')
            ->where('job.state IN (:active)')
            ->andWhere('job.lastProgressAt <= :before')
            ->setParameter('active', [JobState::Queued, JobState::Running])
            ->setParameter('before', $before)
            // Oldest first, so a capped run always retires the jobs that have
            // been misleading somebody the longest.
            ->orderBy('job.lastProgressAt', 'ASC')
            ->setMaxResults($limit)
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
