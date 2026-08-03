<?php

namespace App\Repository\User;

use App\Entity\User\User;
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
    /**
     * Advisory lock id for creating the first administrator. Arbitrary but
     * fixed — 'plm1' as an integer — and used nowhere else, so it cannot
     * collide with another lock in the same database.
     */
    private const int FIRST_ADMIN_LOCK = 0x706C6D31;

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

    /**
     * One page of users, keyset by id — the walk a backfill makes over every
     * account on the install.
     *
     * QueryBuilder because `id > :afterId` is a comparison findBy() cannot
     * state, and keyset rather than OFFSET because each batch flushes and
     * clears: an offset walk would skip rows as the set shifts underneath it.
     *
     * @return list<User>
     */
    public function findBatchAfterId(int $afterId, int $limit): array
    {
        return $this->createQueryBuilder('usr')
            ->where('usr.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('usr.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Map account ids to the id of the user that owns them.
     *
     * Push subscriptions belong to a user but changes are recorded per
     * account, so delivery has to resolve one to the other. One query rather
     * than hydrating an Account per changed id — and a query over a second
     * entity entirely, which no finder on this repository can reach.
     *
     * @param list<int> $accountIds
     *
     * @return array<int,int> accountId => userId
     */
    public function findOwnersOfAccounts(array $accountIds): array
    {
        if (count($accountIds) === 0) {
            return [];
        }

        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('a.id AS accountId', 'u.id AS userId')
            ->from(\App\Entity\Mail\Account::class, 'a')
            ->join('a.usr', 'u')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $accountIds)
            ->getQuery()
            ->getScalarResult();

        $owners = [];

        foreach ($rows as $row) {
            $owners[(int) $row['accountId']] = (int) $row['userId'];
        }

        return $owners;
    }

    /**
     * Every user row, soft-deleted ones included.
     *
     * Deliberately not countUndeleted(): this is what decides whether the
     * unauthenticated /install page is open. Counting only live users would
     * mean soft-deleting the last account re-opens an endpoint that mints an
     * administrator.
     */
    public function countAll(): int
    {
        return $this->count([]);
    }

    /**
     * Persist $user as the first administrator, or return false if someone
     * else got there first.
     *
     * The lock is the point, and is why this is not a plain persist+flush in a
     * service. /install is unauthenticated by definition — it has to be, there
     * is nobody to authenticate yet — so its only protection is that the
     * install has no users. Two requests arriving together both read zero and
     * both create an admin unless the count and the insert happen inside one
     * transaction that nothing else can interleave with. Postgres advisory
     * locks are the only way to hold a row that does not exist yet.
     *
     * The one raw statement is `pg_advisory_xact_lock`, which is a lock
     * primitive rather than a query over data — there is nothing for Doctrine's
     * API to express, and it must run on the connection the transaction below
     * commits on.
     */
    public function createFirstAdmin(User $user): bool
    {
        $entityManager = $this->getEntityManager();

        return $entityManager->getConnection()->transactional(function () use ($entityManager, $user): bool {
            $entityManager->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(?)',
                [self::FIRST_ADMIN_LOCK],
            );

            if (0 !== $this->countAll()) {
                return false;
            }

            $user->roles = [User::ROLE_ADMIN];

            $entityManager->persist($user);
            $entityManager->flush();

            return true;
        });
    }

    /** A null criterion is Doctrine's IS NULL, so this needs no query of its own. */
    public function countUndeleted(): int
    {
        return $this->count(['deletedAt' => null]);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', \get_class($user)));
        }

        $user->password = $newHashedPassword;

        $this->add($user, true);
    }

    /**
     * Every query that should not see soft-deleted users.
     *
     * A builder rather than results, because its callers page and sort it —
     * the admin list hands it to a paginator. The query is still written here,
     * and nowhere else, which is what stops a caller from forgetting the
     * filter.
     *
     * The `andWhere` matters: this used to build the expression and drop it on
     * the floor — `$qb->expr()->isNull(...)` returns a value, it does not
     * modify the builder — so the filter was a no-op and every caller,
     * findOneByEmailExcept included, still matched deleted rows.
     */
    public function createUndeletedQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('user')
            ->andWhere('user.deletedAt IS NULL');
    }

    /**
     * How many undeleted users hold ROLE_ADMIN.
     *
     * Native SQL with an explicit cast, because `roles` is a `json` column and
     * Postgres defines no LIKE operator on that type — the DQL version failed
     * with "operator does not exist: json ~~ unknown". Casting to text makes it
     * an ordinary substring test.
     *
     * A substring test rather than a containment one is safe here: ROLE_ADMIN
     * is the only role ever stored (see UserEntityModel::ROLES), so there is no
     * longer role name it could match a prefix of by accident. The quotes are
     * part of the pattern so it cannot match a bare word elsewhere in the JSON.
     */
    public function countAdmins(): int
    {
        return (int) $this->getEntityManager()
            ->getConnection()
            ->executeQuery(
                // "user" quoted: it is a reserved word in Postgres, which is
                // why the entity maps to `#[ORM\Table(name: '`user`')]`.
                'SELECT COUNT(*) FROM "user" WHERE deleted_at IS NULL AND CAST(roles AS text) LIKE :role',
                ['role' => '%"ROLE_ADMIN"%'],
            )
            ->fetchOne();
    }

    /**
     * Hand-written for the exclusion: "every undeleted user with this address
     * except this one" needs `id != :id`, which findOneBy() cannot say.
     *
     * @throws NonUniqueResultException
     */
    public function findOneByEmailExcept(string $email, User $user): ?User
    {
        $qb = $this->createUndeletedQueryBuilder();
        $qb
            ->andWhere('user.email = :email')
            ->setParameter('email', $email);

        if (null !== $user->id) {
            $qb
                ->andWhere('user.id != :id')
                ->setParameter('id', $user->id);
        }

        $result = $qb->getQuery()->getOneOrNullResult();

        return $result;
    }

    /**
     * The admin user list, filtered. A builder for the same reason
     * createUndeletedQueryBuilder() is one, and hand-written because a LIKE
     * across three case-folded columns is three expressions joined by OR —
     * findBy() states equality on fields, and nothing else.
     */
    public function createSearchQueryBuilder(?string $search): QueryBuilder
    {
        $qb = $this->createUndeletedQueryBuilder();

        if (null !== $search && 2 < strlen($search)) {
            // Same discarded-expression bug as createUndeletedQueryBuilder had:
            // orX() was built and never passed to andWhere(), so searching did
            // nothing at all and every search returned the unfiltered list.
            //
            // The pattern was wrong too. sprintf('%%s%', $search) is not
            // "%search%" — `%%` is a literal percent and the trailing `%` is a
            // truncated conversion spec, so the argument was never interpolated.
            $qb
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->like('LOWER(user.email)', ':search'),
                    $qb->expr()->like('LOWER(user.nameFirst)', ':search'),
                    $qb->expr()->like('LOWER(user.nameLast)', ':search'),
                ))
                ->setParameter('search', '%' . mb_strtolower($search) . '%');
        }

        return $qb;
    }
}
