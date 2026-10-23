<?php

declare(strict_types=1);

namespace App\Repository\AddressBook;

use App\Entity\AddressBook\AddressBook;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
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
     * How many address books each of a user's connections mirrors, keyed by
     * the connection's id — for the Nextcloud disconnect confirmation in
     * Settings → Integrations, which says what goes with it (#158).
     *
     * @return array<int,int>
     */
    public function countByIntegration(UserInterface $user): array
    {
        /** @var list<array{integrationId:int|string,books:int|string}> $rows */
        $rows = $this->createQueryBuilder('book')
            ->select('IDENTITY(book.integration) AS integrationId', 'COUNT(book.id) AS books')
            ->where('book.usr = :user')
            ->groupBy('book.integration')
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['integrationId']] = (int) $row['books'];
        }

        return $counts;
    }

    /**
     * @return list<AddressBook>
     */
    public function findForIntegration(Integration $integration): array
    {
        return $this->findBy(['integration' => $integration], ['name' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * The books mirrored through one connection or one mail account.
     *
     * @return list<AddressBook>
     */
    public function findForHolder(Integration|Account $holder): array
    {
        return $holder instanceof Integration
            ? $this->findForIntegration($holder)
            : $this->findBy(['account' => $holder], ['name' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * A user's books mirroring any of these remote ids, through whichever
     * connection or account — CalendarRepository::findMirroredForUserByRemoteIds()
     * for address books. The subscribe list asks it to find a book another
     * holder already mirrors (#317).
     *
     * @param list<string> $remoteIds
     *
     * @return list<AddressBook>
     */
    public function findForUserByRemoteIds(UserInterface $user, array $remoteIds): array
    {
        if ([] === $remoteIds) {
            return [];
        }

        return $this->findBy(['usr' => $user, 'remoteId' => $remoteIds], ['id' => 'ASC']);
    }

    /**
     * Books not synced since $before, oldest first — the stale sweep.
     *
     * QueryBuilder for the `IS NULL OR <` that findBy() cannot say. A paused
     * connection is skipped: pausing it in Settings → Integrations is how a
     * person says "stop talking to that server". So is a disabled mail
     * account, for the books of a Google or Microsoft one — left joins, as a
     * book has one holder or the other.
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
            ->leftJoin('book.integration', 'integration')
            ->leftJoin('book.account', 'account')
            ->where('integration.isActive = true OR (book.account IS NOT NULL AND (account.isActive IS NULL OR account.isActive = true))')
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
