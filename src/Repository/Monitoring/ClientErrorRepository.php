<?php

declare(strict_types=1);

namespace App\Repository\Monitoring;

use App\Entity\Monitoring\ClientError;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClientError>
 */
final class ClientErrorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClientError::class);
    }

    /**
     * The card's list: most recently seen first.
     *
     * Not by count. A fault that fired four hundred times last month and stopped
     * is history; one that fired twice in the last minute is the one somebody
     * just broke, and the panel is read to answer "what is happening now".
     *
     * @return list<ClientError>
     */
    public function recent(int $limit = 25): array
    {
        /** @var list<ClientError> $rows */
        $rows = $this->createQueryBuilder('e')
            ->orderBy('e.lastSeenAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findOneByFingerprint(string $fingerprint): ?ClientError
    {
        return $this->findOneBy(['fingerprint' => $fingerprint]);
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Bump an existing fault without loading it.
     *
     * DBAL and one statement, because this runs on a route the browser calls
     * unprompted and the common case by far is a fault that already has a row.
     * Reading the entity, changing two fields and flushing would be three
     * round trips to increment a counter — and it would also race with itself,
     * since two tabs can report the same fault at the same moment and both
     * would write `occurrences = 41`.
     */
    public function touch(string $fingerprint, ?string $url, ?string $userAgent): bool
    {
        return 0 < (int) $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE client_error
                SET occurrences = occurrences + 1,
                    last_seen_at = :now,
                    url = COALESCE(:url, url),
                    user_agent = COALESCE(:agent, user_agent)
                WHERE fingerprint = :fingerprint
            SQL,
            [
                'now'         => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                'url'         => $url,
                'agent'       => $userAgent,
                'fingerprint' => $fingerprint,
            ],
        );
    }

    /** Everything, for the "we have dealt with these" button. */
    public function clearAll(): int
    {
        return (int) $this->createQueryBuilder('e')->delete()->getQuery()->execute();
    }
}
