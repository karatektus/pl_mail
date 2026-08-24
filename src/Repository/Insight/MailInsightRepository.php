<?php

declare(strict_types=1);

namespace App\Repository\Insight;

use App\Entity\Insight\MailInsight;
use App\Entity\Mail\Account;
use App\Entity\Mail\MessageThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

class MailInsightRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailInsight::class);
    }

    /** The upsert's read half — see InsightHarvester. */
    public function findOneByDedupe(Account $account, string $dedupeKey): ?MailInsight
    {
        return $this->findOneBy(['account' => $account, 'dedupeKey' => $dedupeKey]);
    }

    /**
     * Dated insights inside the window: a little of the recent past — a
     * parcel that landed this morning is still the answer to "where is my
     * parcel?" — and the near future. Undismissed, active accounts only.
     *
     * @return list<MailInsight> soonest first
     */
    public function upcomingForUser(
        UserInterface $user,
        \DateTimeImmutable $now,
        int $daysAhead = 14,
        int $hoursBack = 18,
        int $limit = 30,
    ): array {
        return $this->createQueryBuilder('i')
            ->join('i.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('i.dismissedAt IS NULL')
            ->andWhere('i.happensAt IS NOT NULL')
            ->andWhere('i.happensAt >= :from')
            ->andWhere('i.happensAt <= :until')
            ->orderBy('i.happensAt', 'ASC')
            // Ties here are the common case, not the exotic one: a parcel's ETA
            // is a DAY, so every parcel due on the same day sits at that day's
            // midnight, and which of them came first was left to the planner.
            // With a cap on the list that also decided which one fell off the
            // end. Newest first among equals, matching the undated list below.
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('user', $user)
            ->setParameter('from', $now->modify(sprintf('-%d hours', $hoursBack)))
            ->setParameter('until', $now->modify(sprintf('+%d days', $daysAhead)))
            ->getQuery()
            ->getResult();
    }

    /**
     * The undated family — GitHub activity and whatever joins it. Recency is
     * the only timeline these have, so the window is on createdAt and the
     * newest speak first.
     *
     * @return list<MailInsight>
     */
    public function recentUndatedForUser(
        UserInterface $user,
        \DateTimeImmutable $now,
        int $daysBack = 7,
        int $limit = 20,
    ): array {
        return $this->createQueryBuilder('i')
            ->join('i.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('i.dismissedAt IS NULL')
            ->andWhere('i.happensAt IS NULL')
            ->andWhere('i.createdAt >= :from')
            ->orderBy('i.createdAt', 'DESC')
            // Same reason, and likelier still: these are written by the
            // harvester as it walks a batch of mail, so a run that reads twenty
            // notifications stamps them all within the same second.
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('user', $user)
            ->setParameter('from', $now->modify(sprintf('-%d days', $daysBack)))
            ->getQuery()
            ->getResult();
    }

    /**
     * Every insight a conversation carries, for the card above the message.
     * Dismissed rows included on purpose: dismissal hides a card from the
     * upcoming panel, not from the mail that plainly states the fact.
     *
     * @return list<MailInsight>
     */
    public function forThread(MessageThread $thread): array
    {
        return $this->findBy(['thread' => $thread], ['happensAt' => 'ASC', 'id' => 'ASC']);
    }
}
