<?php

declare(strict_types=1);

namespace App\Repository\Crypto;

use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Entity\Crypto\ContactCertificate;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactCertificate>
 */
class ContactCertificateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactCertificate::class);
    }

    /**
     * By address, the Current certificate of each before the ones waiting
     * beside it — the order the settings list shows them in.
     *
     * @return list<ContactCertificate>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('certificate')
            ->where('certificate.usr = :usr')
            ->setParameter('usr', $user)
            ->orderBy('certificate.email', 'ASC')
            ->addOrderBy('certificate.status', 'DESC')
            ->addOrderBy('certificate.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ContactCertificate>
     */
    public function findForAddress(User $user, string $email): array
    {
        return $this->findBy(['usr' => $user, 'email' => strtolower(trim($email))], ['id' => 'ASC']);
    }

    public function findCurrent(User $user, string $email): ?ContactCertificate
    {
        return $this->findOneBy(['usr' => $user, 'email' => strtolower(trim($email)), 'status' => ContactKeyStatus::Current]);
    }

    public function findOneForUser(User $user, int $id): ?ContactCertificate
    {
        return $this->findOneBy(['usr' => $user, 'id' => $id]);
    }
}
