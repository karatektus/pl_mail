<?php

declare(strict_types=1);

namespace App\Repository\AddressBook;

use App\Domain\Enum\Calendar\SyncState;
use App\Entity\AddressBook\AddressBook;
use App\Entity\AddressBook\ContactCard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<ContactCard>
 */
class ContactCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactCard::class);
    }

    /**
     * The cards a person can see: every book of theirs, minus what they have
     * deleted here and the server has not yet been told about.
     *
     * QueryBuilder for the join to the book's owner and the case-folded
     * substring match, neither of which findBy() can state.
     *
     * @return list<ContactCard>
     */
    public function search(UserInterface $user, string $query = '', int $limit = 200, ?AddressBook $book = null): array
    {
        $builder = $this->createQueryBuilder('card')
            ->innerJoin('card.addressBook', 'book')
            ->addSelect('book')
            ->where('book.usr = :user')
            ->andWhere('card.syncState != :deleted')
            ->setParameter('user', $user)
            ->setParameter('deleted', SyncState::PendingDelete)
            ->orderBy('LOWER(card.fullName)', 'ASC')
            ->addOrderBy('card.id', 'ASC')
            ->setMaxResults($limit);

        $query = mb_strtolower(trim($query));

        if ('' !== $query) {
            $builder
                ->andWhere('card.searchText LIKE :contains')
                ->setParameter('contains', '%' . addcslashes($query, '%_\\') . '%');
        }

        if (null !== $book) {
            $builder->andWhere('card.addressBook = :book')->setParameter('book', $book);
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * The edits that have not reached the server, oldest first.
     *
     * @return list<ContactCard>
     */
    public function findPending(AddressBook $book): array
    {
        return $this->findBy(
            ['addressBook' => $book, 'syncState' => SyncState::pendingCases()],
            ['id' => 'ASC'],
        );
    }

    /**
     * Every card in a book, keyed by href, for applying a pull in one read
     * rather than a query per remote card. Cards never pushed have no href and
     * are keyed by nothing here — the uid map below finds them.
     *
     * @return array{byHref: array<string, ContactCard>, byUid: array<string, ContactCard>}
     */
    public function indexForBook(AddressBook $book): array
    {
        $byHref = [];
        $byUid  = [];

        foreach ($this->findBy(['addressBook' => $book]) as $card) {
            if (null !== $card->href) {
                $byHref[$card->href] = $card;
            }

            $byUid[$card->uid] = $card;
        }

        return ['byHref' => $byHref, 'byUid' => $byUid];
    }
}
