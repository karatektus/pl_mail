<?php

declare(strict_types=1);

namespace App\Repository\Crypto;

use App\Entity\Crypto\SmimeCertificate;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SmimeCertificate>
 */
class SmimeCertificateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SmimeCertificate::class);
    }

    /**
     * Newest first: where two serve one address, the later one is the one
     * a person renewed to.
     *
     * @return list<SmimeCertificate>
     */
    public function findForUser(User $user): array
    {
        return $this->findBy(['usr' => $user], ['notAfter' => 'DESC', 'id' => 'DESC']);
    }

    public function findOneForUser(User $user, int $id): ?SmimeCertificate
    {
        return $this->findOneBy(['usr' => $user, 'id' => $id]);
    }

    public function findOneByFingerprint(User $user, string $fingerprint): ?SmimeCertificate
    {
        return $this->findOneBy(['usr' => $user, 'fingerprint' => strtolower($fingerprint)]);
    }

    public function hasAny(User $user): bool
    {
        return null !== $this->findOneBy(['usr' => $user]);
    }
}
