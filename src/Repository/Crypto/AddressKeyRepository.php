<?php

declare(strict_types=1);

namespace App\Repository\Crypto;

use App\Entity\Crypto\AddressKey;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AddressKey>
 */
class AddressKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AddressKey::class);
    }

    /**
     * With their bindings fetch-joined: the keyring is never shown without
     * the addresses each key serves, and lazy loading would be one query per
     * key.
     *
     * @return list<AddressKey>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('addressKey')
            ->addSelect('binding')
            ->leftJoin('addressKey.bindings', 'binding')
            ->where('addressKey.usr = :usr')
            ->setParameter('usr', $user)
            ->orderBy('addressKey.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForUser(User $user, int $id): ?AddressKey
    {
        return $this->findOneBy(['usr' => $user, 'id' => $id]);
    }
}
