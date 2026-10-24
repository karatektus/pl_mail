<?php

declare(strict_types=1);

namespace App\Repository\Crypto;

use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Entity\Crypto\ContactKey;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactKey>
 */
class ContactKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactKey::class);
    }

    /**
     * Every key a person holds for their contacts, by address and with the
     * Current key of each address before the ones waiting beside it — the
     * order the settings list shows them in.
     *
     * @return list<ContactKey>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('contactKey')
            ->where('contactKey.usr = :usr')
            ->setParameter('usr', $user)
            ->orderBy('contactKey.email', 'ASC')
            ->addOrderBy('contactKey.status', 'DESC')
            ->addOrderBy('contactKey.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The keys known for one address, Current or Changed.
     *
     * @return list<ContactKey>
     */
    public function findForAddress(User $user, string $email): array
    {
        return $this->findBy(['usr' => $user, 'email' => strtolower(trim($email))], ['id' => 'ASC']);
    }

    public function findCurrent(User $user, string $email): ?ContactKey
    {
        return $this->findOneBy(['usr' => $user, 'email' => strtolower(trim($email)), 'status' => ContactKeyStatus::Current]);
    }

    public function findOneForUser(User $user, int $id): ?ContactKey
    {
        return $this->findOneBy(['usr' => $user, 'id' => $id]);
    }
}
