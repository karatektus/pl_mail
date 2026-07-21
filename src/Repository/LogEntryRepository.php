<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class LogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LogEntry::class);
    }

    /**
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
