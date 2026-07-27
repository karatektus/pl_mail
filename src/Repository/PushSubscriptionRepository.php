<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
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
        return $this->createQueryBuilder('s')
            ->where('s.usr = :user')
            ->setParameter('user', $user)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Subscriptions eligible to actually receive a StateChange: verified, and
     * not past their requested expiry. Filtering here rather than at the call
     * site means there is no path that pushes to an unverified endpoint.
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

    public function findOneOwnedBy(int $id, UserInterface|User $user): ?PushSubscription
    {
        return $this->createQueryBuilder('s')
            ->where('s.id = :id')
            ->andWhere('s.usr = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByDeviceClientId(UserInterface|User $user, string $deviceClientId): ?PushSubscription
    {
        return $this->createQueryBuilder('s')
            ->where('s.usr = :user')
            ->andWhere('s.deviceClientId = :device')
            ->setParameter('user', $user)
            ->setParameter('device', $deviceClientId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
