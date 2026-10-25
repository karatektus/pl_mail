<?php

declare(strict_types=1);

namespace App\Repository\Crypto;

use App\Domain\Enum\Crypto\UserKeyStatus;
use App\Entity\Crypto\UserKey;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserKey>
 */
class UserKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserKey::class);
    }

    /**
     * Oldest first, so a keyring reads in the order it grew.
     *
     * @return list<UserKey>
     */
    public function findForUser(User $user): array
    {
        return $this->findBy(['usr' => $user], ['id' => 'ASC']);
    }

    /**
     * @return list<UserKey>
     */
    public function findActiveForUser(User $user): array
    {
        return $this->findBy(['usr' => $user, 'status' => UserKeyStatus::Active], ['id' => 'ASC']);
    }

    public function findOneForUser(User $user, int $id): ?UserKey
    {
        return $this->findOneBy(['usr' => $user, 'id' => $id]);
    }
}
