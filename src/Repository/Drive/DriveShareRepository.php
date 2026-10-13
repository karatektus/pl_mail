<?php

declare(strict_types=1);

namespace App\Repository\Drive;

use App\Entity\Drive\DriveFile;
use App\Entity\Drive\DriveShare;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DriveShare>
 */
class DriveShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DriveShare::class);
    }

    public function findByToken(string $token): ?DriveShare
    {
        return $this->createQueryBuilder('s')
            ->addSelect('f')
            ->join('s.file', 'f')
            ->where('s.token = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Links on one file that still work, newest first.
     *
     * @return list<DriveShare>
     */
    public function findActiveForFile(DriveFile $file): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.file = :file')
            ->andWhere('s.expiresAt IS NULL OR s.expiresAt > :now')
            ->setParameter('file', $file)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * How many working links each of the user's files has, keyed by file id.
     *
     * @return array<int, int>
     */
    public function activeCountsForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.file) AS file, COUNT(s.id) AS links')
            ->join('s.file', 'f')
            ->where('f.usr = :user')
            ->andWhere('s.expiresAt IS NULL OR s.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->groupBy('s.file')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['file']] = (int) $row['links'];
        }

        return $counts;
    }
}
