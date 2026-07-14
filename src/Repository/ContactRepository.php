<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contact;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    /**
     * Insert a new contact or increment frequency + refresh display name
     * on an existing one. Uses a raw DBAL upsert for performance so
     * we can process thousands of addresses without loading entities.
     *
     * @param array<array{email: string, name: string|null}> $addresses
     */
    public function upsertBatch(User $user, array $addresses): void
    {
        if (count($addresses) === 0) {
            return;
        }

        $conn  = $this->getEntityManager()->getConnection();
        $now   = new DateTimeImmutable();
        $userId = $user->getId();

        foreach ($addresses as $addr) {
            $email = mb_strtolower(trim($addr['email'] ?? ''));

            if ($email === '') {
                continue;
            }

            // Sanitize display name: strip empty / same-as-email values.
            $name = trim($addr['name'] ?? '');

            if ($name === '' || mb_strtolower($name) === $email) {
                $name = null;
            }

            $conn->executeStatement(
                <<<'SQL'
                INSERT INTO contact (usr_id, email, display_name, frequency, first_seen_at, last_seen_at, created_at, updated_at)
                VALUES (:userId, :email, :name, 1, :now, :now, :now, :now)
                ON CONFLICT (usr_id, email) DO UPDATE
                    SET frequency    = contact.frequency + 1,
                        last_seen_at = :now,
                        updated_at   = :now,
                        display_name = COALESCE(NULLIF(:name, ''), contact.display_name)
                SQL,
                [
                    'userId' => $userId,
                    'email'  => $email,
                    'name'   => $name,
                    'now'    => $now,
                ],
                [
                    'userId' => Types::INTEGER,
                    'email'  => Types::STRING,
                    'name'   => Types::STRING,
                    'now'    => Types::DATETIME_IMMUTABLE,
                ]
            );
        }
    }

    /**
     * Autocomplete: return up to $limit contacts whose email or display_name
     * starts with (or contains) the query string, ordered by frequency desc.
     *
     * @return Contact[]
     */
    public function findForAutocomplete(UserInterface $user, string $query, int $limit = 8): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->where('c.usr = :user')
            ->andWhere(
                'LOWER(c.email) LIKE :prefix OR LOWER(c.displayName) LIKE :prefix'
                . ' OR LOWER(c.email) LIKE :contains OR LOWER(c.displayName) LIKE :contains',
            )
            ->setParameter('user', $user)
            ->setParameter('prefix',   mb_strtolower($query) . '%')
            ->setParameter('contains', '%' . mb_strtolower($query) . '%')
            ->orderBy('c.frequency', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
