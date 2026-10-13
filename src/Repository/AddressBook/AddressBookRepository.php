<?php

declare(strict_types=1);

namespace App\Repository\AddressBook;

use App\Entity\AddressBook\AddressBook;
use App\Entity\Integration\Integration;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<AddressBook>
 */
class AddressBookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AddressBook::class);
    }

    /**
     * @return list<AddressBook>
     */
    public function findForUser(UserInterface $user): array
    {
        return $this->findBy(['usr' => $user], ['name' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * The books a new contact may be created in — the picker on the contact
     * form offers nothing the server would refuse.
     *
     * @return list<AddressBook>
     */
    public function findWritableForUser(UserInterface $user): array
    {
        return $this->findBy(['usr' => $user, 'isReadOnly' => false], ['name' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @return list<AddressBook>
     */
    public function findForIntegration(Integration $integration): array
    {
        return $this->findBy(['integration' => $integration], ['name' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Books not synced since $before, oldest first — the stale sweep.
     *
     * QueryBuilder for the `IS NULL OR <` that findBy() cannot say. A paused
     * connection is skipped: pausing it in Settings → Integrations is how a
     * person says "stop talking to that server".
     *
     * @return list<AddressBook>
     */
    public function findDueForSync(DateTimeImmutable $before, int $limit = 200): array
    {
        // COALESCE rather than ordering on the column: Postgres sorts NULLs
        // last on ASC, which would put a book that has never synced behind
        // every one that merely has not synced lately.
        return $this->createQueryBuilder('book')
            ->addSelect('COALESCE(book.lastSyncedAt, :epoch) AS HIDDEN dueSince')
            ->innerJoin('book.integration', 'integration')
            ->where('integration.isActive = true')
            ->andWhere('book.lastSyncedAt IS NULL OR book.lastSyncedAt < :before')
            ->setParameter('before', $before)
            ->setParameter('epoch', new DateTimeImmutable('@0'))
            ->orderBy('dueSince', 'ASC')
            ->addOrderBy('book.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
