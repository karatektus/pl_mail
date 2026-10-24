<?php

declare(strict_types=1);

namespace App\Repository\Crypto;

use App\Domain\Enum\Crypto\UserKeyCopyKind;
use App\Entity\Crypto\UserKey;
use App\Entity\Crypto\UserKeyCopy;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserKeyCopy>
 */
class UserKeyCopyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserKeyCopy::class);
    }

    /**
     * Every copy of every one of a person's user keys.
     *
     * QueryBuilder for the join: the copy knows its key, not its person, and
     * "whose is it" is the one question every caller asks first.
     *
     * @return list<UserKeyCopy>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('copy')
            ->addSelect('userKey')
            ->join('copy.userKey', 'userKey')
            ->where('userKey.usr = :usr')
            ->setParameter('usr', $user)
            ->orderBy('copy.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForUser(User $user, int $id): ?UserKeyCopy
    {
        return $this->createQueryBuilder('copy')
            ->join('copy.userKey', 'userKey')
            ->where('userKey.usr = :usr')
            ->andWhere('copy.id = :id')
            ->setParameter('usr', $user)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<UserKeyCopy>
     */
    public function findPhrasesFor(UserKey $userKey): array
    {
        return $this->findBy(['userKey' => $userKey, 'kind' => UserKeyCopyKind::Phrase]);
    }
}
