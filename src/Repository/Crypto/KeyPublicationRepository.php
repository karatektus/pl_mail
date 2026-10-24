<?php

declare(strict_types=1);

namespace App\Repository\Crypto;

use App\Entity\Crypto\AddressKey;
use App\Entity\Crypto\KeyPublication;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KeyPublication>
 */
class KeyPublicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KeyPublication::class);
    }

    /**
     * @return list<KeyPublication>
     */
    public function findForKey(AddressKey $key): array
    {
        /** @var list<KeyPublication> */
        return $this->createQueryBuilder('p')
            ->andWhere('p.addressKey = :key')
            ->setParameter('key', $key)
            ->orderBy('p.host')
            ->addOrderBy('p.email')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every row of the person's, grouped by address key id, for the keyring
     * view: one query, not one per key.
     *
     * @return array<int, list<KeyPublication>>
     */
    public function findForUserByKey(User $user): array
    {
        /** @var list<KeyPublication> $rows */
        $rows = $this->createQueryBuilder('p')
            ->join('p.addressKey', 'k')
            ->andWhere('k.usr = :user')
            ->setParameter('user', $user)
            ->orderBy('p.host')
            ->addOrderBy('p.email')
            ->getQuery()
            ->getResult();

        $byKey = [];

        foreach ($rows as $row) {
            $byKey[(int) $row->addressKey->id][] = $row;
        }

        return $byKey;
    }
}
