<?php

declare(strict_types=1);

namespace App\Repository\Mail;

use App\Domain\Helper\AddressHelper;
use App\Entity\Mail\Contact;
use App\Entity\User\User;
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
        $userId = $user->id;

        foreach ($addresses as $addr) {
            $email = AddressHelper::email($addr['email'] ?? '');
            $isCorrespondent = (bool) ($addr['correspondent'] ?? false);

            // A header that failed to parse yields fragments (a bare `"Doe`, an
            // empty local part). Those used to become contacts of their own and
            // then turned up in autocomplete.
            if (false === AddressHelper::isValidEmail($email)) {
                continue;
            }

            // Sanitize display name: strip quoting, then empty / same-as-email
            // values.
            $name = AddressHelper::name($addr['name'] ?? null);

            if ($name === '' || mb_strtolower($name) === $email) {
                $name = null;
            }

            $conn->executeStatement(
                <<<'SQL'
                INSERT INTO contact (usr_id, email, display_name, frequency, first_seen_at, last_seen_at, created_at, updated_at, is_correspondent)
                VALUES (:userId, :email, :name, 1, :now, :now, :now, :now, :isCorrespondent)
                ON CONFLICT (usr_id, email) DO UPDATE
                    SET frequency    = contact.frequency + 1,
                        is_correspondent = contact.is_correspondent OR EXCLUDED.is_correspondent,
                        last_seen_at = :now,
                        updated_at   = :now,
                        display_name = COALESCE(NULLIF(:name, ''), contact.display_name)

                SQL,
                [
                    'userId' => $userId,
                    'email'  => $email,
                    'name'   => $name,
                    'now'    => $now,
                    'isCorrespondent' => $isCorrespondent,
                ],
                [
                    'userId' => Types::INTEGER,
                    'email'  => Types::STRING,
                    'name'   => Types::STRING,
                    'now'    => Types::DATETIME_IMMUTABLE,
                    'isCorrespondent' => Types::BOOLEAN,
                ]
            );
        }
    }

    /**
     * Ids of every contact, oldest first — the backfill cursor for address
     * normalisation.
     *
     * @return list<int>
     */
    public function findIdsAfter(int $afterId, int $limit): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.id')
            ->where('c.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $rows);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Contact>
     */
    public function findByIds(array $ids): array
    {
        if (count($ids) === 0) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
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

    /** @return array<string,true> normalised correspondent emails as a set */
    public function findCorrespondentEmails(UserInterface $user): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.email')
            ->where('c.usr = :user')
            ->andWhere('c.isCorrespondent = true')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        $set = [];

        foreach ($rows as $row) {
            $set[mb_strtolower(trim((string) $row['email']))] = true;
        }

        return $set;
    }

    /**
     * @param string[] $emails
     *
     * @return array<string, Contact> lowercase email => contact
     */
    public function findByEmailsForUser(UserInterface $user, array $emails): array
    {
        if (count($emails) === 0) {
            return [];
        }

        $contacts = $this->createQueryBuilder('c')
            ->where('c.usr = :user')
            ->andWhere('LOWER(c.email) IN (:emails)')
            ->setParameter('user', $user)
            ->setParameter('emails', array_map(mb_strtolower(...), $emails))
            ->getQuery()
            ->getResult();

        $indexed = [];

        foreach ($contacts as $contact) {
            $indexed[mb_strtolower((string) $contact->email)] = $contact;
        }

        return $indexed;
    }

    /**
     * Insert placeholder contacts for addresses typed into a draft but never
     * sent to. frequency 0 keeps them out of the ranked suggestions until a
     * real send (or a sync) promotes them via upsertBatch().
     *
     * @param string[] $emails
     */
    public function createUnsent(User $user, array $emails): void
    {
        if (count($emails) === 0) {
            return;
        }

        $conn = $this->getEntityManager()->getConnection();
        $now  = new DateTimeImmutable();

        foreach ($emails as $email) {
            $email = AddressHelper::email($email);

            if (false === AddressHelper::isValidEmail($email)) {
                continue;
            }

            $conn->executeStatement(
                <<<'SQL'
            INSERT INTO contact (usr_id, email, display_name, frequency, first_seen_at, last_seen_at, created_at, updated_at)
            VALUES (:userId, :email, NULL, 0, :now, :now, :now, :now)
            ON CONFLICT (usr_id, email) DO NOTHING
            SQL,
                [
                    'userId' => $user->id,
                    'email'  => $email,
                    'now'    => $now,
                ],
                [
                    'userId' => Types::INTEGER,
                    'email'  => Types::STRING,
                    'now'    => Types::DATETIME_IMMUTABLE,
                ],
            );
        }
    }
}
