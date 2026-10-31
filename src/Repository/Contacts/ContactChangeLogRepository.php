<?php

declare(strict_types=1);

namespace App\Repository\Contacts;

use App\Entity\Contacts\ContactChangeLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reading contact_change_log in its two scopes: rows about contacts (the
 * ContactCard state) and rows about address books (the AddressBook state).
 *
 * The scope is a method rather than an argument for the reason
 * CalendarChangeLogRepository gives: a filter forgotten at a call site is not
 * an error but a renamed address book reported as a changed card.
 *
 * @extends ServiceEntityRepository<ContactChangeLog>
 */
final class ContactChangeLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactChangeLog::class);
    }

    public function latestCardSequenceForUser(int $userId): int
    {
        return $this->aggregate($this->cards($userId), 'MAX');
    }

    public function oldestCardSequenceForUser(int $userId): int
    {
        return $this->aggregate($this->cards($userId), 'MIN');
    }

    /** @return list<ContactChangeLog> one more than $limit, to tell whether more remain */
    public function cardChangesSinceForUser(int $userId, int $since, int $limit): array
    {
        return $this->since($this->cards($userId), $since, $limit);
    }

    public function latestBookSequenceForUser(int $userId): int
    {
        return $this->aggregate($this->books($userId), 'MAX');
    }

    public function oldestBookSequenceForUser(int $userId): int
    {
        return $this->aggregate($this->books($userId), 'MIN');
    }

    /** @return list<ContactChangeLog> */
    public function bookChangesSinceForUser(int $userId, int $since, int $limit): array
    {
        return $this->since($this->books($userId), $since, $limit);
    }

    private function cards(int $userId): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->where('c.userId = :user')
            ->andWhere('c.contactId IS NOT NULL')
            ->setParameter('user', $userId);
    }

    private function books(int $userId): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->where('c.userId = :user')
            ->andWhere('c.contactId IS NULL')
            ->setParameter('user', $userId);
    }

    private function aggregate(QueryBuilder $builder, string $aggregate): int
    {
        $result = $builder->select(sprintf('%s(c.sequence)', $aggregate))->getQuery()->getSingleScalarResult();

        return null === $result ? 0 : (int) $result;
    }

    /** @return list<ContactChangeLog> */
    private function since(QueryBuilder $builder, int $since, int $limit): array
    {
        return $builder
            ->andWhere('c.sequence > :since')
            ->setParameter('since', $since)
            ->orderBy('c.sequence', 'ASC')
            ->setMaxResults($limit + 1)
            ->getQuery()
            ->getResult();
    }
}
