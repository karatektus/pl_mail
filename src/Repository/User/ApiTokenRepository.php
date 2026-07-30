<?php

declare(strict_types=1);

namespace App\Repository\User;

use App\Entity\User\ApiToken;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    /**
     * Look up a presented secret. Hashing first means the lookup is a single
     * indexed equality match, and no plaintext ever reaches a query.
     *
     * Revoked tokens are excluded here rather than by the caller, so there is
     * no path that authenticates one by forgetting the check.
     */
    public function findActiveBySecret(string $secret): ?ApiToken
    {
        return $this->createQueryBuilder('t')
            ->addSelect('u')
            ->join('t.usr', 'u')
            ->where('t.tokenHash = :hash')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('hash', ApiToken::hash($secret))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<ApiToken>
     */
    public function findForUser(UserInterface|User $user): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.usr = :user')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneOwnedBy(int $id, UserInterface|User $user): ?ApiToken
    {
        return $this->createQueryBuilder('t')
            ->where('t.id = :id')
            ->andWhere('t.usr = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
