<?php

declare(strict_types=1);

namespace App\Repository\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\UploadedBlob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UploadedBlob>
 */
class UploadedBlobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UploadedBlob::class);
    }

    /**
     * Scoped to the account, so an upload belonging to another account can
     * never resolve.
     */
    public function findOneOwnedBy(int $id, Account $account): ?UploadedBlob
    {
        return $this->createQueryBuilder('b')
            ->where('b.id = :id')
            ->andWhere('b.account = :account')
            ->setParameter('id', $id)
            ->setParameter('account', $account)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<UploadedBlob>
     */
    public function findOlderThan(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }
}
