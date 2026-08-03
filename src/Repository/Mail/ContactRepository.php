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
     * on an existing one.
     *
     * ON CONFLICT, which Doctrine's API has no form of: a sync processes
     * thousands of addresses, most of which already exist, and the
     * find-then-insert-or-update alternative is both a query per address and a
     * race two concurrent syncs lose. The increment is computed by the database
     * from the row it finds, so no read is needed to write it.
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
     * QueryBuilder for the keyset bound and the projection: findBy() can
     * neither say `id > :afterId` nor return bare ids, and hydrating a batch of
     * contacts to read one column off each is what a cursor exists to avoid.
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

        return $this->findBy(['id' => $ids], ['id' => 'ASC']);
    }

    /**
     * Autocomplete: return up to $limit contacts whose email or display_name
     * starts with (or contains) the query string, ordered by frequency desc.
     *
     * QueryBuilder because the test is four case-folded LIKEs joined by OR.
     * findBy() states equality on fields; prefix-or-substring across two
     * columns is not something it can be asked.
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

    /**
     * QueryBuilder for the projection: the categoriser wants a set of strings
     * to test membership against, and hydrating a Contact per address to build
     * it would make classifying one message cost the whole address book.
     *
     * @return array<string,true> normalised correspondent emails as a set
     */
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
     * QueryBuilder because the match is on LOWER(email): addresses are stored
     * as they arrived and compared case-insensitively, and findBy() compares
     * the stored value rather than an expression over it.
     *
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
     * ON CONFLICT DO NOTHING for the same reason upsertBatch() needs ON
     * CONFLICT: "insert unless it is already there" cannot be written as a
     * check followed by an insert without losing the race between them, and
     * a draft that mentions an existing contact must not fail to save.
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
