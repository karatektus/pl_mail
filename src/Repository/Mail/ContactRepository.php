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
     * starts with (or contains) the query string, most-written-to first and
     * most-recently-seen within that.
     *
     * QueryBuilder because the test is four case-folded LIKEs joined by OR.
     * findBy() states equality on fields; prefix-or-substring across two
     * columns is not something it can be asked.
     *
     * **Frequency alone used to be the whole order**, which left every tie to
     * whatever order Postgres happened to return — and ties are the common
     * case, not the exotic one: most of an address book is people seen once.
     * So the list a user saw could reshuffle between keystrokes, and the
     * colleague mailed last week sat below a stranger from two years ago
     * because both had frequency 1.
     *
     * Both surfaces read this one method — the JMAP `Contact/autocomplete` and
     * the web composer's route — so the ranking is the same wherever somebody
     * composes. That is the point of it being here rather than in either
     * caller; two rankings would make the suggestion order depend on which
     * device you happened to be addressing mail from.
     *
     * The CASE is how "NULLS LAST" is spelled: **DQL cannot say it** (the ORM
     * 3.6 parser rejects `NULLS LAST` outright), and Postgres orders NULLs
     * FIRST on a DESC sort, so leaving it to the database default would put
     * never-seen contacts at the top of every suggestion list — silently, and
     * exactly backwards.
     *
     * It cannot fire today: `last_seen_at` is NOT NULL (Version20260714094203,
     * never altered since) and both write paths guarantee a value — the entity
     * constructor sets it, and upsertBatch above writes `:now` on insert and on
     * conflict alike. It is here for the day somebody makes the column
     * nullable — an imported contact, a merge — because that change would
     * otherwise invert this list and nothing would fail.
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
            ->addOrderBy('CASE WHEN c.lastSeenAt IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('c.lastSeenAt', 'DESC')
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
     * Whether this one address is somebody the user has mailed.
     *
     * The set-returning projection above is what classifying a mailbox wants;
     * this is what explaining a single message wants, and loading the whole
     * address book to answer for one sender would be that same mistake in
     * miniature.
     */
    public function isCorrespondent(UserInterface $user, string $email): bool
    {
        $email = mb_strtolower(trim($email));

        if ('' === $email) {
            return false;
        }

        return 0 < (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.usr = :user')
            ->andWhere('LOWER(c.email) = :email')
            ->andWhere('c.isCorrespondent = true')
            ->setParameter('user', $user)
            ->setParameter('email', $email)
            ->getQuery()
            ->getSingleScalarResult();
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
