<?php

declare(strict_types=1);

namespace App\Repository\Contacts;

use App\Domain\Enum\Calendar\SyncState;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactEmail;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    /** A contact whose names or company contain :like. */
    private const string MATCHES = '(LOWER(c.displayName) LIKE :like OR LOWER(c.givenName) LIKE :like OR LOWER(c.familyName) LIKE :like OR LOWER(c.nickname) LIKE :like OR LOWER(c.company) LIKE :like)';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    /**
     * Address-book hits for recipient autocomplete: one row per address, with
     * its contact, for ContactSuggester to merge with the harvest.
     *
     * Rooted at the email rather than the contact, so a contact's emails come
     * back as rows of their own: typing "ada" offers Ada's work and home
     * addresses both, and the contact's collection is never hydrated with
     * only the addresses that happened to match (a fetch-joined collection
     * filtered by the WHERE is a partial collection Doctrine then treats as
     * the whole one). A contact matches by any of its names, its company or
     * the address itself.
     *
     * @return list<ContactEmail>
     */
    public function findForAutocomplete(UserInterface $user, string $query, int $limit): array
    {
        /** @var list<ContactEmail> */
        return $this->getEntityManager()->createQueryBuilder()
            ->select('e', 'c')
            ->from(ContactEmail::class, 'e')
            ->innerJoin('e.contact', 'c')
            ->where('c.usr = :user')
            ->andWhere('c.trashedAt IS NULL')
            ->andWhere(self::MATCHES . ' OR LOWER(e.email) LIKE :like')
            ->setParameter('user', $user)
            ->setParameter('like', $this->like($query))
            ->orderBy('LOWER(c.displayName)', 'ASC')
            ->addOrderBy('e.isPrimary', 'DESC')
            ->addOrderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The contacts of one synced book with an edit waiting to go to its server.
     *
     * @return list<Contact>
     */
    public function findPending(AddressBook $book): array
    {
        return $this->findBy(
            ['addressBook' => $book, 'syncState' => SyncState::pendingCases()],
            ['id' => 'ASC'],
        );
    }

    /**
     * Every contact of a synced book by its card's href and by its UID, for
     * AddressBookSync to match what the server sends against.
     *
     * @return array{byHref: array<string, Contact>, byUid: array<string, Contact>}
     */
    public function indexForBook(AddressBook $book): array
    {
        $byHref = [];
        $byUid  = [];

        foreach ($this->findBy(['addressBook' => $book]) as $contact) {
            if (null !== $contact->href) {
                $byHref[$contact->href] = $contact;
            }

            $byUid[$contact->uid] = $contact;
        }

        return ['byHref' => $byHref, 'byUid' => $byUid];
    }

    /**
     * How many contacts each of these books holds, for the settings list.
     *
     * @param list<AddressBook> $books
     *
     * @return array<int, int> book id => count
     */
    public function countByBook(array $books): array
    {
        if ([] === $books) {
            return [];
        }

        /** @var list<array{book: int, total: int}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.addressBook) AS book', 'COUNT(c.id) AS total')
            ->where('c.addressBook IN (:books)')
            ->andWhere('c.trashedAt IS NULL')
            ->setParameter('books', $books)
            ->groupBy('c.addressBook')
            ->getQuery()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['book']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Paged address-book list. Tag filtering is applied in PHP after the
     * page is fetched: tags live in a JSON column, and portable DQL has no
     * jsonb-containment operator, so a DB-side filter would lock this query
     * to one database. v1 tradeoff — with a tag selected, fewer than $limit
     * rows may come back for a page.
     *
     * $book narrows the list to one address book: its id, or 'local' for
     * plMail's own contacts.
     *
     * @return list<Contact>
     */
    public function findForUser(UserInterface $user, ?string $search, ?string $tag, string $sort, int $limit, int $offset, int|string|null $book = null): array
    {
        $qb = $this->listed($user, $search, $book);

        match ($sort) {
            'recent' => $qb->orderBy('c.updatedAt', 'DESC'),
            default  => $qb->orderBy('LOWER(c.displayName)', 'ASC'),
        };

        $qb->addOrderBy('c.id', 'ASC')->setMaxResults($limit)->setFirstResult($offset);

        /** @var list<Contact> $contacts */
        $contacts = $qb->getQuery()->getResult();

        if (null !== $tag && '' !== $tag) {
            $contacts = array_values(array_filter(
                $contacts,
                static fn (Contact $contact): bool => \in_array($tag, $contact->tags, true),
            ));
        }

        return $contacts;
    }

    /**
     * How many contacts findForUser() would return across all pages for
     * this search. Tag filtering happens in PHP after the page is read, so it
     * cannot be counted here.
     */
    public function countForUser(UserInterface $user, ?string $search, int|string|null $book = null): int
    {
        return (int) $this->listed($user, $search, $book)
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The search matches an address through EXISTS rather than a join: a
     * join would make a contact with three addresses three rows (so a page
     * could cut one short and repeat it on the next), and a fetch-joined,
     * filtered collection would show a contact with only the address that
     * matched.
     */
    private function listed(UserInterface $user, ?string $search, int|string|null $book): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.usr = :user')
            ->andWhere('c.trashedAt IS NULL')
            ->setParameter('user', $user);

        if ('local' === $book) {
            $qb->andWhere('c.addressBook IS NULL');
        } elseif (null !== $book) {
            $qb->andWhere('c.addressBook = :book')->setParameter('book', (int) $book);
        }

        if (null !== $search && '' !== trim($search)) {
            $emails = $this->getEntityManager()->createQueryBuilder()
                ->select('1')
                ->from(ContactEmail::class, 'e')
                ->where('e.contact = c')
                ->andWhere('LOWER(e.email) LIKE :like');

            $qb->andWhere(sprintf('%s OR EXISTS(%s)', self::MATCHES, $emails->getDQL()))
                ->setParameter('like', $this->like($search));
        }

        return $qb;
    }

    /**
     * Case-insensitive "contains", with the user's % and _ taken literally.
     */
    private function like(string $query): string
    {
        return '%' . addcslashes(mb_strtolower(trim($query)), '%_\\') . '%';
    }

    /**
     * Every tag used by the user's address book, for the list page's tag
     * chips. Flattened in PHP: tags live in a JSON column, and portable DQL
     * has no jsonb-unnest. One indexed read, cheap next to the list query.
     *
     * @return list<string>
     */
    public function findAllTagsForUser(UserInterface $user): array
    {
        /** @var list<array{tags: list<string>}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.tags AS tags')
            ->where('c.usr = :user')
            ->andWhere('c.trashedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $tags = [];
        foreach ($rows as $row) {
            foreach ((array) ($row['tags'] ?? []) as $tag) {
                $tag = trim((string) $tag);
                if ('' !== $tag && !isset($tags[$tag])) {
                    $tags[$tag] = true;
                }
            }
        }

        $out = array_keys($tags);
        natcasesort($out);

        return array_values($out);
    }

    /**
     * The person's contacts that already have one of these addresses, for
     * quick add's "already in your contacts". Rooted at the email for the
     * reason findForAutocomplete() is, and compared lower-cased: addresses
     * are stored as typed.
     *
     * @param list<string> $emails
     *
     * @return list<ContactEmail>
     */
    public function findByEmails(UserInterface $user, array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(static fn (string $e): string => mb_strtolower(trim($e)), $emails))));

        if ([] === $emails) {
            return [];
        }

        /** @var list<ContactEmail> */
        return $this->getEntityManager()->createQueryBuilder()
            ->select('e', 'c')
            ->from(ContactEmail::class, 'e')
            ->innerJoin('e.contact', 'c')
            ->where('c.usr = :user')
            ->andWhere('c.trashedAt IS NULL')
            ->andWhere('LOWER(e.email) IN (:emails)')
            ->setParameter('user', $user)
            ->setParameter('emails', $emails)
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }
}
