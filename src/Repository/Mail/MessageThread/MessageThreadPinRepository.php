<?php

declare(strict_types=1);

namespace App\Repository\Mail\MessageThread;

use App\Entity\Mail\MessageThread;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Pinned conversations (#654): how many a person has, and which pins have
 * run out.
 *
 * The ORDER that puts pins first is not here but on ListSortOrder, because it
 * has to ride on every list query those build; this holds the questions asked
 * of the pins themselves.
 */
class MessageThreadPinRepository extends ServiceEntityRepository
{
    /**
     * "This message's conversation is pinned now", over the `m` alias of a
     * raw query on message — what JMAP's $pinned keyword filter and its
     * `pinned` sort ask (EmailFilterCompiler, EmailQueryRunner). The same
     * rule as ListSortOrder::pinnedFirst() and MessageThread::isPinned(): a
     * pin past its end is no pin.
     */
    public const string PINNED_SQL = 'EXISTS (SELECT 1 FROM message_thread pin_t WHERE pin_t.id = m.thread_id'
        . ' AND pin_t.pinned_at IS NOT NULL AND (pin_t.pinned_until IS NULL OR pin_t.pinned_until > NOW()))';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageThread::class);
    }

    /**
     * How many conversations this person has pinned right now, across every
     * account — the number the "pin anyway?" reminder compares with.
     *
     * Per person rather than per account because the reminder is about the
     * top of the lists they read, and a unified inbox shows every account's
     * pins at once. Accounts with mail switched off are left out for the same
     * reason: their conversations are in no list.
     *
     * Ended pins are not counted though the sweep may not have cleared them,
     * and nor are pins in the bin. Trashing clears a pin, but a conversation
     * that reached the bin some other way (another client, a sync) keeps its
     * columns, and nobody sees it at the top of anything.
     *
     * QueryBuilder for the join to Account and the comparison with the clock.
     */
    public function countActiveForUser(UserInterface $user, ?DateTimeImmutable $now = null): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.mailEnabled = true')
            ->andWhere('t.pinnedAt IS NOT NULL')
            ->andWhere('t.pinnedUntil IS NULL OR t.pinnedUntil > :now')
            ->andWhere('t.binned = false')
            ->setParameter('user', $user)
            ->setParameter('now', $now ?? new DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Pinned conversations whose pin has ended, oldest end first, capped so a
     * backlog after downtime cannot hold the worker.
     *
     * The lists already treat these as unpinned; clearing them is for what
     * reads the columns without the clock beside them — a JMAP client's copy
     * of the $pinned keyword, which only changes when something records the
     * change.
     *
     * Messages are fetch-joined because the sweep records every email of each
     * conversation for JMAP. Two statements for the reason
     * MessageThreadSnoozeRepository::findDueSnoozed() gives: a limit on a
     * fetch-joined query caps the joined rows, not the threads.
     *
     * @return list<MessageThread>
     */
    public function findEnded(DateTimeImmutable $now, int $limit): array
    {
        $ids = $this->createQueryBuilder('t')
            ->select('t.id')
            ->where('t.pinnedAt IS NOT NULL')
            ->andWhere('t.pinnedUntil IS NOT NULL')
            ->andWhere('t.pinnedUntil <= :now')
            ->setParameter('now', $now)
            ->orderBy('t.pinnedUntil', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->addSelect('m')
            ->leftJoin('t.messages', 'm')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('t.pinnedUntil', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
