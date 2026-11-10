<?php

declare(strict_types=1);

namespace App\Repository\Contacts\ContactEmail;

use App\Entity\Contacts\ContactEmail;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Which contact an email address belongs to.
 *
 * Only contacts that are not in the bin, and matched without regard to case:
 * the address on a mail and the one in the address book are the same address
 * however either was typed.
 *
 * @extends ServiceEntityRepository<ContactEmail>
 */
class ContactEmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactEmail::class);
    }

    /**
     * The contact behind each of these addresses that has one, in one query:
     * lower-cased address => lowest contact id.
     *
     * @param list<string> $emails lower-cased
     *
     * @return array<string, int>
     */
    public function contactIdsByEmail(User $user, array $emails): array
    {
        if ([] === $emails) {
            return [];
        }

        /** @var list<array{email: string, id: int|string}> $rows */
        $rows = $this->getEntityManager()->createQuery(
            'SELECT LOWER(e.email) AS email, MIN(c.id) AS id FROM App\Entity\Contacts\ContactEmail e
             JOIN e.contact c
             WHERE c.usr = :user AND c.trashedAt IS NULL AND LOWER(e.email) IN (:emails)
             GROUP BY e.email'
        )
            ->setParameter('user', $user)
            ->setParameter('emails', $emails)
            ->getArrayResult();

        $ids = [];

        foreach ($rows as $row) {
            $ids[$row['email']] = (int) $row['id'];
        }

        return $ids;
    }

    /** The contact behind one address, or null. */
    public function contactIdByEmail(User $user, string $email): ?int
    {
        $id = $this->getEntityManager()->createQuery(
            'SELECT c.id FROM App\Entity\Contacts\ContactEmail e
             JOIN e.contact c
             WHERE c.usr = :user AND c.trashedAt IS NULL AND LOWER(e.email) = :email'
        )
            ->setParameter('user', $user)
            ->setParameter('email', $email)
            ->setMaxResults(1)
            ->getOneOrNullResult(Query::HYDRATE_SINGLE_SCALAR);

        return null === $id ? null : (int) $id;
    }
}
