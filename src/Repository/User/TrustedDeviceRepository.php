<?php

declare(strict_types=1);

namespace App\Repository\User;

use App\Entity\User\TrustedDevice;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<TrustedDevice>
 */
class TrustedDeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrustedDevice::class);
    }

    /**
     * Resolve a cookie secret to a live grant.
     *
     * Revoked and expired rows are excluded here rather than by the caller, so
     * there is no path that honours one by forgetting the check — the same
     * reasoning as ApiTokenRepository::findActiveBySecret().
     */
    public function findActiveBySecret(string $secret, UserInterface|User $user, string $firewall): ?TrustedDevice
    {
        return $this->createQueryBuilder('d')
            ->where('d.tokenHash = :hash')
            ->andWhere('d.usr = :user')
            ->andWhere('d.firewall = :firewall')
            ->andWhere('d.revokedAt IS NULL')
            ->andWhere('d.expiresAt > :now')
            ->setParameter('hash', TrustedDevice::hash($secret))
            ->setParameter('user', $user)
            ->setParameter('firewall', $firewall)
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<TrustedDevice>
     */
    public function findActiveForUser(UserInterface|User $user): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.usr = :user')
            ->andWhere('d.revokedAt IS NULL')
            ->andWhere('d.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', new DateTimeImmutable())
            ->orderBy('d.lastUsedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneOwnedBy(int $id, UserInterface|User $user): ?TrustedDevice
    {
        return $this->createQueryBuilder('d')
            ->where('d.id = :id')
            ->andWhere('d.usr = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Withdraw every grant the user holds. Used by "sign out everywhere", and
     * unconditionally whenever 2FA is turned off or its secret is replaced —
     * a device trusted against the old secret must not survive it.
     *
     * @return int number of grants withdrawn
     */
    public function revokeAllForUser(UserInterface|User $user): int
    {
        return $this->createQueryBuilder('d')
            ->update()
            ->set('d.revokedAt', ':now')
            ->where('d.usr = :user')
            ->andWhere('d.revokedAt IS NULL')
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Drop rows that can no longer authorise anything, so the table does not
     * grow without bound on an install that has been running for years.
     */
    public function pruneExpired(DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('d')
            ->delete()
            ->where('d.expiresAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
