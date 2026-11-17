<?php

declare(strict_types=1);

namespace App\Repository\Mail\MessageThread;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
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
     * The newest received email of a conversation, as raw SQL over message:
     * ANCHOR_SQL_HEAD, the conversation's id column, then ANCHOR_SQL_TAIL.
     * It is the email a pinned conversation puts at the top of the email
     * list (#615). ThreadPinService::anchorOf() says the same in PHP —
     * received is not filed as Sent or Drafts, falling back to the newest of
     * all, ties to the higher id.
     */
    private const string ANCHOR_SQL_HEAD = '(SELECT anchor_m.id FROM message anchor_m WHERE anchor_m.thread_id = ';

    private const string ANCHOR_SQL_TAIL = ' ORDER BY (NOT EXISTS (SELECT 1 FROM message_label anchor_ml JOIN label anchor_l ON anchor_l.id = anchor_ml.label_id'
        . " WHERE anchor_ml.message_id = anchor_m.id AND anchor_l.role IN ('sent', 'drafts'))) DESC,"
        . ' anchor_m.received_at DESC NULLS LAST, anchor_m.id DESC LIMIT 1)';

    /**
     * "This email is pinned now", over the `m` alias of a raw query on
     * message — what JMAP's $pinned keyword filter and its `pinned` sort ask
     * (EmailFilterCompiler, EmailQueryRunner). The email list's rule
     * (ThreadPinService::emailPinned()): its own pin, or it is the newest
     * received email of a pinned conversation. A pin past its end is no pin.
     */
    public const string PINNED_SQL = '((m.pinned_at IS NOT NULL AND (m.pinned_until IS NULL OR m.pinned_until > NOW()))'
        . ' OR (EXISTS (SELECT 1 FROM message_thread pin_t WHERE pin_t.id = m.thread_id'
        . ' AND pin_t.pinned_at IS NOT NULL AND (pin_t.pinned_until IS NULL OR pin_t.pinned_until > NOW()))'
        . ' AND m.id = ' . self::ANCHOR_SQL_HEAD . 'm.thread_id' . self::ANCHOR_SQL_TAIL . '))';

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
        return $this->countActiveConversations($user, $now) + $this->countActiveEmails($user, $now);
    }

    /**
     * Pins on single emails (#615), counted toward the same reminder: a pin
     * is a pin to the person looking at the top of their list, whichever list
     * it was made in. Spam and the bin left out, as the conversation count
     * leaves binned conversations out.
     */
    private function countActiveEmails(UserInterface $user, ?DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Message::class, 'm')
            ->join('m.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.mailEnabled = true')
            ->andWhere('m.pinnedAt IS NOT NULL')
            ->andWhere('m.pinnedUntil IS NULL OR m.pinnedUntil > :now')
            ->andWhere('NOT EXISTS (SELECT setAside.id FROM ' . Label::class . ' setAside WHERE setAside MEMBER OF m.labels AND setAside.role IN (:setAside))')
            ->setParameter('user', $user)
            ->setParameter('now', $now ?? new DateTimeImmutable())
            ->setParameter('setAside', [LabelRole::Trash, LabelRole::Spam])
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countActiveConversations(UserInterface $user, ?DateTimeImmutable $now): int
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

    /**
     * Single emails whose own pin has run out, for the sweep — findEnded()'s
     * twin (#615).
     *
     * @return list<Message>
     */
    public function findEndedEmails(DateTimeImmutable $now, int $limit): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('m')
            ->from(Message::class, 'm')
            ->where('m.pinnedAt IS NOT NULL')
            ->andWhere('m.pinnedUntil IS NOT NULL')
            ->andWhere('m.pinnedUntil <= :now')
            ->setParameter('now', $now)
            ->orderBy('m.pinnedUntil', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The ids of the emails the reader's pinned conversations put at the top
     * of the email list: each one's newest received email (ANCHOR_SQL_HEAD).
     *
     * Asked once per page rather than inside the list's ORDER BY, which DQL
     * cannot spell (a sub-select with its own ORDER and LIMIT), and which
     * would ask it per row. A person has a handful of pinned conversations,
     * so this is a handful of ids.
     *
     * @return list<int>
     */
    public function anchorIdsForUser(UserInterface $user, ?DateTimeImmutable $now = null): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT ' . self::ANCHOR_SQL_HEAD . 't.id' . self::ANCHOR_SQL_TAIL . ' FROM message_thread t'
                . ' JOIN account a ON a.id = t.account_id'
                . ' WHERE a.usr_id = :user AND t.pinned_at IS NOT NULL AND (t.pinned_until IS NULL OR t.pinned_until > :now)',
            [
                'user' => $user instanceof User ? $user->id : 0,
                'now'  => ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        return array_values(array_filter(array_map(intval(...), $ids), static fn (int $id): bool => $id > 0));
    }
}
