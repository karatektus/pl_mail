<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Messages by the id a provider gave them: Gmail message ids and Graph ids.
 */
class MessageProviderIdRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * The rows behind a set of Gmail ids, scoped to one owner.
     *
     * Scoped through the user rather than the account: plMail attributes a
     * Gmail message to whichever of the owner's accounts it was actually
     * addressed to, so the row for an id that arrived on one account's
     * history feed may well hang off a sibling.
     *
     * @param list<string> $gmailIds
     *
     * @return list<Message>
     */
    public function findByGmailIdsForUser(User $user, array $gmailIds): array
    {
        if (0 === count($gmailIds)) {
            return [];
        }

        return $this->createQueryBuilder('m')
            ->innerJoin('m.account', 'a')
            ->where('a.usr = :usr')
            ->andWhere('m.gmailId IN (:gmailIds)')
            ->setParameter('usr', $user)
            ->setParameter('gmailIds', $gmailIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * The one row for a Gmail id among the owner's accounts, for the batch
     * handler's "already stored?" check.
     *
     * Scoped through the user and not the account, for the reason given on
     * findByGmailIdsForUser(): a message fetched by one Gmail account may be
     * attributed to a sibling. It used to be a bare findOneBy(['gmailId']),
     * which searched every user on the install — a gmailId is only unique per
     * account, so another user's row could be found and enriched with this
     * user's labels. QueryBuilder because the owner is behind an association.
     */
    public function findOneByGmailIdForUser(User $user, string $gmailId): ?Message
    {
        return $this->createQueryBuilder('m')
            ->innerJoin('m.account', 'a')
            ->where('a.usr = :usr')
            ->andWhere('m.gmailId = :gmailId')
            ->setParameter('usr', $user)
            ->setParameter('gmailId', $gmailId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * A few of an account's Gmail ids, newest first: something to fetch again
     * for the quota probe without asking Gmail what there is.
     *
     * @return list<string>
     */
    public function findSomeGmailIdsForAccount(Account $account, int $limit): array
    {
        return array_map(strval(...), $this->createQueryBuilder('m')
            ->select('m.gmailId')
            ->where('m.account = :account')
            ->andWhere('m.gmailId IS NOT NULL')
            ->setParameter('account', $account)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult());
    }

    /**
     * Which of these Gmail ids the owner already has, as ids and nothing
     * else: what a listing's batch asks before it fetches.
     *
     * @param list<string> $gmailIds
     *
     * @return list<string>
     */
    public function findStoredGmailIdsForUser(User $user, array $gmailIds): array
    {
        if (0 === count($gmailIds)) {
            return [];
        }

        return array_map(strval(...), $this->createQueryBuilder('m')
            ->select('m.gmailId')
            ->innerJoin('m.account', 'a')
            ->where('a.usr = :usr')
            ->andWhere('m.gmailId IN (:gmailIds)')
            ->setParameter('usr', $user)
            ->setParameter('gmailIds', $gmailIds)
            ->getQuery()
            ->getSingleColumnResult());
    }

    /**
     * QueryBuilder for the join to the owning user and for the single-column
     * projection.
     *
     * @return list<string>
     */
    public function findSyncedGraphIdsForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.graphId')
            ->join('m.account', 'a')
            ->andWhere('a.usr = :user')
            ->andWhere('m.graphId IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map('strval', array_column($rows, 'graphId')));
    }
}
