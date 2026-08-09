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
     *
     * QueryBuilder for `expiresAt > :now`: findOneBy() compares a field to a
     * value, and liveness is a comparison against the clock.
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
     * The grant this browser already holds, whether or not it is still live.
     *
     * findActiveBySecret() answers "may this cookie skip the prompt?", so it
     * excludes anything expired. This answers a different question — "is the
     * browser being trusted right now the one this row was minted for?" — and
     * an expired row is still that browser. Trusting it again should move the
     * expiry it already has rather than leave a dead sibling behind and insert
     * a second line in Settings → Security for one laptop.
     *
     * Revoked rows are still excluded, and that asymmetry is the point: expiry
     * is the clock running out, revocation is the user saying no. A revoked
     * grant that came back because the browser still had the cookie would make
     * the revoke button a suggestion.
     */
    public function findReusableBySecret(string $secret, UserInterface|User $user, string $firewall): ?TrustedDevice
    {
        return $this->findOneBy([
            'tokenHash' => TrustedDevice::hash($secret),
            'usr'       => $user,
            'firewall'  => $firewall,
            'revokedAt' => null,
        ]);
    }

    /**
     * QueryBuilder for the same expiry comparison as findActiveBySecret().
     *
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
        return $this->findOneBy(['id' => $id, 'usr' => $user]);
    }

    /**
     * Withdraw every grant the user holds. Used by "sign out everywhere", and
     * unconditionally whenever 2FA is turned off or its secret is replaced —
     * a device trusted against the old secret must not survive it.
     *
     * A bulk UPDATE because it has to reach grants that are not loaded and must
     * not become loaded: this runs on a 2FA change, where hydrating every
     * device a user has ever trusted to stamp one column would put unrelated
     * entities into the unit of work that is about to flush the secret.
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
     *
     * A bulk DELETE for the `<` bound and because nothing here needs an
     * entity — a retention sweep that hydrated its victims would scale with
     * the very history it exists to remove.
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
