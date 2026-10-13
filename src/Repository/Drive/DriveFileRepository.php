<?php

declare(strict_types=1);

namespace App\Repository\Drive;

use App\Entity\Drive\DriveFile;
use App\Entity\Drive\DriveFolder;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DriveFile>
 */
class DriveFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DriveFile::class);
    }

    /**
     * @return list<DriveFile>
     */
    public function findForUser(User $user, ?DriveFolder $folder = null): array
    {
        $qb = $this->createQueryBuilder('f')
            ->where('f.usr = :user')
            ->setParameter('user', $user)
            ->orderBy('f.createdAt', 'DESC');

        if (null === $folder) {
            $qb->andWhere('f.folder IS NULL');
        } else {
            $qb->andWhere('f.folder = :folder')->setParameter('folder', $folder);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Every file the user owns, newest first, folders preloaded — for the
     * compose picker, which shows the whole Drive at once.
     *
     * @return list<DriveFile>
     */
    public function findAllForUser(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.folder', 'd')
            ->addSelect('d')
            ->where('f.usr = :user')
            ->setParameter('user', $user)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Files directly inside any of the given folders.
     *
     * @param list<int> $folderIds
     *
     * @return list<DriveFile>
     */
    public function findInFolders(User $user, array $folderIds): array
    {
        if ([] === $folderIds) {
            return [];
        }

        return $this->createQueryBuilder('f')
            ->where('f.usr = :user')
            ->andWhere('IDENTITY(f.folder) IN (:ids)')
            ->setParameter('user', $user)
            ->setParameter('ids', $folderIds)
            ->getQuery()
            ->getResult();
    }

    public function findOneForUser(User $user, int $id): ?DriveFile
    {
        return $this->findOneBy(['id' => $id, 'usr' => $user]);
    }

    public function totalSizeForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COALESCE(SUM(f.sizeBytes), 0)')
            ->where('f.usr = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
