<?php

declare(strict_types=1);

namespace App\Repository\Mail;

use App\Domain\Helper\AddressHelper;
use App\Entity\Mail\TrustedImageSender;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrustedImageSender>
 */
class TrustedImageSenderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrustedImageSender::class);
    }

    public function isTrusted(User $user, ?string $address): bool
    {
        $address = AddressHelper::email($address);

        if ('' === $address) {
            return false;
        }

        return null !== $this->findOneBy(['usr' => $user, 'address' => $address]);
    }

    /**
     * Idempotent by construction. The unique constraint is the arbiter rather
     * than a preceding SELECT: two tabs on the same message, or a double click,
     * both reach here and one of them loses the race — which is a caught
     * exception and a no-op, not a 500 in front of someone who did nothing
     * wrong.
     */
    public function trust(User $user, ?string $address): void
    {
        if (null === $address || false === AddressHelper::isValidEmail($address)) {
            return;
        }

        $entity          = new TrustedImageSender();
        $entity->usr     = $user;
        $entity->address = $address;

        $manager = $this->getEntityManager();

        try {
            $manager->persist($entity);
            $manager->flush();
        } catch (UniqueConstraintViolationException) {
            // Already trusted. The desired state is the state we are in.
            $manager->detach($entity);
        }
    }

    public function distrust(User $user, ?string $address): void
    {
        $address = AddressHelper::email($address);

        if ('' === $address) {
            return;
        }

        $existing = $this->findOneBy(['usr' => $user, 'address' => $address]);

        if (null === $existing) {
            return;
        }

        $this->getEntityManager()->remove($existing);
        $this->getEntityManager()->flush();
    }
}
