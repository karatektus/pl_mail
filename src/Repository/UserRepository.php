<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 *
 * @method User|null find($id, $lockMode = null, $lockVersion = null)
 * @method User|null findOneBy(array $criteria, array $orderBy = null)
 * @method User[]    findAll()
 * @method User[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function add(User $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(User $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function countUndeleted(): int
    {
        return $this->createQueryBuilder('user')
            ->select('count(user.id)')
            ->where('user.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', \get_class($user)));
        }

        $user->setPassword($newHashedPassword);

        $this->add($user, true);
    }

    public function createUndeletedQueryBuilder(): QueryBuilder
    {
        $qb = $this->createQueryBuilder('user');
        $qb->expr()->isNull('user.deletedAt');

        return $qb;
    }

    /**
     * @throws NonUniqueResultException
     */
    public function findOneByEmailExcept(string $email, User $user): ?User
    {
        $qb = $this->createUndeletedQueryBuilder();
        $qb
            ->andWhere('user.email = :email')
            ->setParameter('email', $email);

        if (null !== $user->getId()) {
            $qb
                ->andWhere('user.id != :id')
                ->setParameter('id', $user->getId());
        }

        $result = $qb->getQuery()->getOneOrNullResult();

        return $result;
    }

    public function createSearchQueryBuilder(?string $search): QueryBuilder
    {
        $qb = $this->createUndeletedQueryBuilder();

        if (null !== $search && 2 < strlen($search)) {
            $qb->expr()->orX(
                $qb->expr()->like('user.email', ':search'),
                $qb->expr()->like('user.nameFirst', ':search'),
                $qb->expr()->like('user.nameLast', ':search'),
            );

            $qb->setParameter('search', sprintf('%%s%', $search));
        }

        return $qb;
    }
}
