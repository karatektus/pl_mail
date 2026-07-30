<?php

namespace App\Repository\Mail;

use App\Entity\Mail\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Criteria;
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

    /**
     * @return iterable<Account>
     */
    public function findForUserOrdered(UserInterface $user): array
    {
        return $this->createQueryBuilder('account')
            ->addSelect('LOWER(COALESCE(account.email, account.username)) AS HIDDEN sortName')
            ->andWhere('account.usr = :usr')
            ->setParameter('usr', $user)
            ->orderBy('account.sortOrder', 'ASC')
            ->addOrderBy('sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countForUser(UserInterface $user): int
    {
        return $this->count(['usr' => $user]);
    }

    /**
     * One account that has something encrypted stored on it, or null when the
     * install has none yet.
     *
     * Hydrating the result is the point: EncryptedStringType decrypts on read,
     * so a wrong APP_ENCRYPTION_KEY surfaces here as a conversion error rather
     * than silently later. See EncryptionKeyProbe.
     */
    public function findOneWithStoredCredentials(): ?Account
    {
        $criteria = Criteria::create()
            ->where(Criteria::expr()->orX(
                Criteria::expr()->neq('password', null),
                Criteria::expr()->neq('oauthRefreshToken', null),
                Criteria::expr()->neq('oauthAccessToken', null),
            ))
            ->setMaxResults(1);

        return $this->matching($criteria)->first() ?: null;
    }
}
