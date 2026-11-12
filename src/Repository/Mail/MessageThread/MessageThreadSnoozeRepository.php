<?php

declare(strict_types=1);

namespace App\Repository\Mail\MessageThread;

use App\Entity\Mail\Account;
use App\Entity\Mail\MessageThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Snoozed threads: how many a user has, and which are due to wake.
 */
class MessageThreadSnoozeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageThread::class);
    }

    /**
     * How many of the user's conversations are snoozed right now.
     *
     * Counts the column rather than the Snoozed label, because the column is
     * what the wake sweep acts on: a thread whose time has passed but which
     * the sweep has not reached yet is no longer snoozed, and counting the
     * label would keep it in the total for up to a minute. Only used to decide
     * whether the sidebar shows the entry at all.
     *
     * QueryBuilder for the join to Account and for `snoozedUntil > :now`,
     * neither of which count() can state.
     */
    public function countSnoozedForUser(UserInterface $user): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.mailEnabled = true')
            ->andWhere('t.snoozedUntil IS NOT NULL')
            ->andWhere('t.snoozedUntil > :now')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Snoozed conversations whose wake time has passed.
     *
     * Ordered oldest-first so a backlog drains in the order the user asked
     * for, and capped so one enormous backlog cannot hold the worker.
     *
     * QueryBuilder for the `<= :now` comparison, and for the fetch-join: the
     * sweep touches every message of every thread it wakes, so leaving the
     * collections lazy would be an N+1 across the whole backlog.
     *
     * TWO STATEMENTS, because the cap and the fetch-join cannot share one:
     * setMaxResults() on a query that fetch-joins a collection limits the
     * JOINED rows, so a batch of 200 was 200 messages rather than 200 threads,
     * and the last thread in it arrived with some of its messages cut off. The
     * ids are picked (and capped) first; the threads are then loaded whole.
     *
     * @return list<MessageThread>
     */
    public function findDueSnoozed(\DateTimeImmutable $now, int $limit): array
    {
        $ids = $this->createQueryBuilder('t')
            ->select('t.id')
            ->where('t.snoozedUntil IS NOT NULL')
            ->andWhere('t.snoozedUntil <= :now')
            ->setParameter('now', $now)
            ->orderBy('t.snoozedUntil', 'ASC')
            // Snoozing a selection gives every thread in it the same wake time,
            // so ties here are the norm rather than the exception. Everything
            // due is woken eventually whatever the order, but with a cap on the
            // batch the boundary is arbitrary without this — and a sweep that
            // processes the same backlog in a different order each time is
            // needlessly hard to reason about when one of them goes wrong.
            ->addOrderBy('t.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $ids) {
            return [];
        }

        /** @var list<MessageThread> */
        return $this->createQueryBuilder('t')
            ->addSelect('m')
            ->leftJoin('t.messages', 'm')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('t.snoozedUntil', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
