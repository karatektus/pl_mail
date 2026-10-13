<?php

declare(strict_types=1);

namespace App\Repository\Drive;

use App\Entity\Drive\DriveShareReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DriveShareReport> */
final class DriveShareReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DriveShareReport::class);
    }

    /** @return list<DriveShareReport> newest first */
    public function findOpen(int $limit = 100): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.resolvedAt IS NULL')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<DriveShareReport> */
    public function findOpenForShare(int $shareId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.resolvedAt IS NULL')
            ->andWhere('IDENTITY(r.share) = :share')
            ->setParameter('share', $shareId)
            ->getQuery()
            ->getResult();
    }

    /** @return list<DriveShareReport> */
    public function findOpenForOwner(int $ownerId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.resolvedAt IS NULL')
            ->andWhere('r.ownerId = :owner')
            ->setParameter('owner', $ownerId)
            ->getQuery()
            ->getResult();
    }

    /** Whether this address already reported this link since $since (repeat clicks count once). */
    public function hasRecent(int $shareId, ?string $ip, \DateTimeImmutable $since): bool
    {
        if (null === $ip) {
            return false;
        }

        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('IDENTITY(r.share) = :share')
            ->andWhere('r.reporterIp = :ip')
            ->andWhere('r.createdAt >= :since')
            ->setParameter('share', $shareId)
            ->setParameter('ip', $ip)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.resolvedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
