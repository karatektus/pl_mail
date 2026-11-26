<?php

declare(strict_types=1);

namespace App\Repository\Mail\UnifiedInboxAccount;

use App\Entity\Mail\Account;
use App\Entity\Mail\UnifiedInboxAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<UnifiedInboxAccount>
 */
class UnifiedInboxAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnifiedInboxAccount::class);
    }

    /**
     * Every account of the reader's with the inbox it is assigned to, or null
     * for one that never was: account id => [inbox id or null, switched on].
     *
     * One read for both, because both are asked on every sidebar render — the
     * accounts to know what an unassigned one is, the assignments to know
     * where the rest are. Ids rather than entities: the answer feeds an IN
     * list and a lookup table, and hydrating every account to read its id
     * would be a heavier version of the same query.
     *
     * @return array<int, array{inbox: int|null, active: bool}>
     */
    public function inboxesByAccount(UserInterface $user): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('a.id AS accountId', 'a.mailEnabled AS active', 'IDENTITY(m.inbox) AS inboxId')
            ->from(Account::class, 'a')
            ->leftJoin(UnifiedInboxAccount::class, 'm', 'WITH', 'm.account = a')
            ->where('a.usr = :user')
            ->setParameter('user', $user)
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['accountId']] = [
                'inbox'  => null === $row['inboxId'] ? null : (int) $row['inboxId'],
                'active' => true === (bool) $row['active'],
            ];
        }

        return $map;
    }

    /**
     * The accounts each of the reader's inboxes was ASSIGNED, inbox id =>
     * account ids — only the rows that exist, so an account in the first inbox
     * because it was never moved is in none of these lists. What a config
     * backup carries: restored the same way, it is in the first inbox again.
     *
     * @return array<int, list<int>>
     */
    public function assignedAccountIdsByInbox(UserInterface $user): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.inbox) AS inboxId', 'IDENTITY(m.account) AS accountId')
            ->join('m.inbox', 'i')
            ->where('i.usr = :user')
            ->setParameter('user', $user)
            ->orderBy('m.account', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['inboxId']][] = (int) $row['accountId'];
        }

        return $map;
    }
}
