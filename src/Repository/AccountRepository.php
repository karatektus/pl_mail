<?php

namespace App\Repository;

use App\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * @return iterable<Account>
     */
    public function findForUserOrderedByName(UserInterface $user): array
    {
        return $this->createQueryBuilder('account')
            ->addSelect('LOWER(COALESCE(account.email, account.username)) AS HIDDEN sortName')
            ->andWhere('account.usr = :usr')
            ->setParameter('usr', $user)
            ->orderBy('sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
