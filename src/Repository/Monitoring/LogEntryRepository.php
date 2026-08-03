<?php

declare(strict_types=1);

namespace App\Repository\Monitoring;

use App\Entity\Monitoring\LogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

class LogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LogEntry::class);
    }

    /**
     * One page of the admin log view.
     *
     * QueryBuilder because the level filter is `>=` — "warning and above" is a
     * range, and findBy() only ever compares a field to a value. The channel
     * clause is conditional on top of it, which is the other thing a criteria
     * array cannot carry.
     *
     * @return list<LogEntry>
     */
    public function search(int $minLevel, ?string $channel, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.level >= :minLevel')
            ->setParameter('minLevel', $minLevel)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (null !== $channel && '' !== $channel) {
            $qb->andWhere('l.channel = :channel')
                ->setParameter('channel', $channel);
        }

        return $qb->getQuery()->getResult();
    }

    /** Same `>=` level range as search(), so the same reason to keep it. */
    public function countSearch(int $minLevel, ?string $channel): int
    {
        $qb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.level >= :minLevel')
            ->setParameter('minLevel', $minLevel);

        if (null !== $channel && '' !== $channel) {
            $qb->andWhere('l.channel = :channel')
                ->setParameter('channel', $channel);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * The channels that actually appear, for the filter dropdown.
     *
     * DISTINCT over one column: Doctrine's API returns entities, so the
     * alternative is loading every log row on the install to reduce it to a
     * handful of strings in PHP.
     *
     * @return list<string>
     */
    public function distinctChannels(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('DISTINCT l.channel')
            ->orderBy('l.channel', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(static fn (array $row): string => (string) $row['channel'], $rows));
    }

    /**
     * Deletes exactly what search() would list for the same filter, so the
     * admin's "clear" button can never remove more than what is on screen.
     *
     * A bulk DELETE, and hand-written for the same `>=` range search() needs.
     * Hydrating the rows would mean loading the log to delete the log.
     *
     * @return int Number of deleted entries
     */
    public function deleteSearch(int $minLevel, ?string $channel): int
    {
        $qb = $this->createQueryBuilder('l')
            ->delete()
            ->where('l.level >= :minLevel')
            ->setParameter('minLevel', $minLevel);

        if (null !== $channel && '' !== $channel) {
            $qb->andWhere('l.channel = :channel')
                ->setParameter('channel', $channel);
        }

        return (int) $qb->getQuery()->execute();
    }

    /**
     * How often each of these exact messages has been logged, and when it last
     * was — the Gmail push panel's whole read.
     *
     * A GROUP BY with two aggregates, which Doctrine's API has no form of. The
     * alternative is one count() per message plus one findOneBy() per message
     * for the timestamp: sixteen queries where this is one.
     *
     * @param list<string> $messages
     *
     * @return list<array<string,mixed>>
     */
    public function countsByMessage(array $messages): array
    {
        if (0 === count($messages)) {
            return [];
        }

        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT message, MAX(level_name) AS level_name, COUNT(*) AS hits, MAX(created_at) AS last_at
               FROM log_entry
              WHERE message IN (:messages)
              GROUP BY message
              ORDER BY last_at DESC',
            ['messages' => $messages],
            ['messages' => ArrayParameterType::STRING],
        );
    }

    /**
     * The newest error-or-worse entry whose message starts with $prefix.
     *
     * Hand-written on both counts: `level >= :minLevel` is a range and
     * `message LIKE :prefix` is a pattern, and findOneBy() states neither.
     *
     * @return array<string,mixed>|null
     */
    public function findLatestErrorStartingWith(string $prefix, int $minLevel): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT message, context, created_at
               FROM log_entry
              WHERE message LIKE :prefix
                AND level >= :minLevel
              ORDER BY created_at DESC
              LIMIT 1',
            ['prefix' => $prefix.'%', 'minLevel' => $minLevel],
        );

        return false === $row ? null : $row;
    }

    /** Bulk DELETE for the `<` bound, and so retention never loads what it drops. */
    public function pruneOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->where('l.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
