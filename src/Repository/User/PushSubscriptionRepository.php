<?php

declare(strict_types=1);

namespace App\Repository\User;

use App\Entity\User\PushSubscription;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    /**
     * @return list<PushSubscription>
     */
    public function findForUser(UserInterface|User $user): array
    {
        return $this->findBy(['usr' => $user], ['createdAt' => 'DESC']);
    }

    /**
     * Subscriptions eligible to actually receive a StateChange: verified, and
     * not past their requested expiry. Filtering here rather than at the call
     * site means there is no path that pushes to an unverified endpoint.
     *
     * QueryBuilder because "no expiry, or one still in the future" is an OR
     * between a null test and a comparison — findBy() ANDs field equalities and
     * can express neither half.
     *
     * @return list<PushSubscription>
     */
    public function findDeliverableForUser(int $userId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.usr = :user')
            ->andWhere('s.verified = true')
            ->andWhere('s.expires IS NULL OR s.expires > :now')
            ->setParameter('user', $userId)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /** Scoped to the owner, so another user's id can never resolve. */
    public function findOneOwnedBy(int $id, UserInterface|User $user): ?PushSubscription
    {
        return $this->findOneBy(['id' => $id, 'usr' => $user]);
    }

    public function findOneByDeviceClientId(UserInterface|User $user, string $deviceClientId): ?PushSubscription
    {
        return $this->findOneBy(['usr' => $user, 'deviceClientId' => $deviceClientId]);
    }
}
