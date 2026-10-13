<?php

declare(strict_types=1);

namespace App\Repository\Drive;

use App\Entity\Drive\DriveFolder;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DriveFolder>
 */
class DriveFolderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DriveFolder::class);
    }

    /** @return DriveFolder[] */
    public function findForUser(User $user, ?DriveFolder $parent = null): array
    {
        $qb = $this->createQueryBuilder('f')
            ->where('f.usr = :user')
            ->setParameter('user', $user)
            ->orderBy('f.name', 'ASC');

        if (null === $parent) {
            $qb->andWhere('f.parent IS NULL');
        } else {
            $qb->andWhere('f.parent = :parent')->setParameter('parent', $parent);
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneForUser(User $user, int $id): ?DriveFolder
    {
        return $this->findOneBy(['id' => $id, 'usr' => $user]);
    }

    /**
     * The folder and every folder below it, as ids. Iterative rather than a
     * recursive CTE so it stays plain DQL; Drive trees are a handful deep.
     *
     * @return list<int>
     */
    public function subtreeIds(DriveFolder $folder): array
    {
        $ids = [$folder->id];
        $frontier = [$folder->id];

        while ([] !== $frontier) {
            $children = $this->createQueryBuilder('f')
                ->select('f.id')
                ->where('IDENTITY(f.parent) IN (:ids)')
                ->setParameter('ids', $frontier)
                ->getQuery()
                ->getSingleColumnResult();

            $frontier = array_values(array_diff(array_map('intval', $children), $ids));
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    /** @return DriveFolder[] breadcrumbs from root to the given folder. */
    public function breadcrumbs(DriveFolder $folder): array
    {
        $crumbs = [];
        $current = $folder;
        while (null !== $current) {
            array_unshift($crumbs, $current);
            $current = $current->parent;
        }

        return $crumbs;
    }
}
