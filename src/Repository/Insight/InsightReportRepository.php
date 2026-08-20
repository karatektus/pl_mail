<?php

declare(strict_types=1);

namespace App\Repository\Insight;

use App\Entity\Insight\InsightReport;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reads over the reported mail — one writer (the report button) and one
 * reader (the admin panel and its export).
 *
 * @extends ServiceEntityRepository<InsightReport>
 */
class InsightReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InsightReport::class);
    }

    /**
     * The dedupe guard behind the report button: has this person already said
     * this about this mail?
     *
     * Scoped to the user and not to the message alone, because two people
     * reporting the same newsletter are two independent observations and the
     * second is worth as much as the first. A unique constraint could not
     * express this — both columns are nullable by design (see the entity), and
     * Postgres lets nulls repeat — so the rule lives here, where it is also
     * the thing that decides what the button says on a second click.
     */
    public function findOneByMessageAndReporter(Message $message, User $reporter): ?InsightReport
    {
        return $this->findOneBy(['message' => $message, 'reportedBy' => $reporter]);
    }

    /**
     * The panel's list: newest first, because a report is a bug report and the
     * fresh ones are the ones still worth reading.
     *
     * @return list<InsightReport>
     */
    public function latest(int $limit = 100, bool $pendingOnly = false): array
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit);

        if (true === $pendingOnly) {
            $qb->andWhere('r.handledAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Everything the export writes, oldest first.
     *
     * Oldest first here and newest first in latest(), deliberately: the panel
     * is read top-down by someone triaging, while the export is read as a
     * corpus, and a corpus in the order the shapes actually arrived is the one
     * where a reader can see a sender change its mail layout over time.
     *
     * @return list<InsightReport>
     */
    public function forExport(bool $pendingOnly = false): array
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.createdAt', 'ASC');

        if (true === $pendingOnly) {
            $qb->andWhere('r.handledAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /** How many are waiting, for the panel's heading and its nav badge. */
    public function countPending(): int
    {
        return $this->countByHandled(false);
    }

    /**
     * How many are done, so the button that sweeps them away can say how many
     * that is.
     *
     * A destructive confirm that names no number asks the admin to take the
     * panel's word for what is about to go, which is the one thing a confirm
     * exists not to do — and this one deletes the only surviving copy of mail
     * somebody reported.
     */
    public function countHandled(): int
    {
        return $this->countByHandled(true);
    }

    private function countByHandled(bool $handled): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere(true === $handled ? 'r.handledAt IS NOT NULL' : 'r.handledAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
