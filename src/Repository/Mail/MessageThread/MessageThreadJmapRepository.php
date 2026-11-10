<?php

declare(strict_types=1);

namespace App\Repository\Mail\MessageThread;

use App\Entity\Mail\MessageThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Threads as JMAP's Thread/get asks for them, scoped to one account.
 */
class MessageThreadJmapRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageThread::class);
    }

    /**
     * JMAP Thread/get: fetch by id, scoped to the account. Messages are
     * eager-joined because a Thread object is nothing but its email id list —
     * findBy() would honour the mapped fetch mode and load them one thread at
     * a time.
     *
     * @param list<int> $ids
     *
     * @return list<MessageThread>
     */
    public function findByAccountAndIds(int $accountId, array $ids): array
    {
        if (count($ids) === 0) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->addSelect('m')
            ->leftJoin('t.messages', 'm')
            ->where('t.account = :account')
            ->andWhere('t.id IN (:ids)')
            ->setParameter('account', $accountId)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}
