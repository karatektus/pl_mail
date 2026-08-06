<?php

declare(strict_types=1);

namespace App\Repository\Push;

use App\Domain\DTO\Push\LastDelivery;
use App\Domain\Enum\PushDeliveryOutcome;
use App\Domain\Enum\PushTransport;
use App\Entity\Push\PushDelivery;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushDelivery>
 */
class PushDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushDelivery::class);
    }

    /**
     * One page of the admin delivery browser.
     *
     * QueryBuilder rather than findBy() because all three filters are optional
     * and independent — findBy() takes a criteria array, so "any user, FCM
     * only, any outcome" would mean building that array conditionally anyway
     * and then losing the join the user filter needs.
     *
     * The user is matched on the id rather than on the entity so the caller can
     * pass what a query string gives it, and the ordering carries `id DESC`
     * behind `createdAt DESC` for the same reason the log browser does: several
     * deliveries of one state change share a timestamp to the second, and
     * without the tie-break the page boundary can show a row twice.
     *
     * @return list<PushDelivery>
     */
    public function search(
        ?int                 $userId,
        ?PushTransport       $transport,
        ?PushDeliveryOutcome $outcome,
        int                  $limit,
        int                  $offset,
    ): array {
        // The owner is fetch-joined rather than left to lazy-load: the table
        // prints an address per row, and a page of fifty deliveries across
        // fifty devices would otherwise be fifty extra SELECTs behind a
        // template loop, where nothing looks like it is querying at all.
        $qb = $this->filtered($userId, $transport, $outcome)
            ->join('d.usr', 'owner')
            ->addSelect('owner')
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $qb->getQuery()->getResult();
    }

    /** The same filter search() applies, so the count describes the same rows. */
    public function countSearch(?int $userId, ?PushTransport $transport, ?PushDeliveryOutcome $outcome): int
    {
        return (int) $this->filtered($userId, $transport, $outcome)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The users who appear in the log at all, for the admin's filter dropdown.
     *
     * DISTINCT over a join rather than every user on the install: an admin
     * filtering by somebody who has never registered a device would get an
     * empty table and no way to tell that from a delivery problem. Ordered by
     * address so the list is stable between visits.
     *
     * @return list<array{id: int, email: string}>
     */
    public function distinctUsers(): array
    {
        /** @var list<array{id: int|string, email: string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('DISTINCT u.id AS id, u.email AS email')
            ->join('d.usr', 'u')
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'email' => (string) $row['email']],
            $rows,
        ));
    }

    /**
     * The newest delivery for each of one user's devices, keyed by device id.
     *
     * Raw DBAL, and this is the query that needs it: `DISTINCT ON` has no DQL
     * form and no registered function, and every alternative is worse. A GROUP
     * BY answers the newest *timestamp* and then needs a second pass to learn
     * what happened at it; one query per device is N round trips for a page
     * that renders in one; and loading the user's whole history to reduce it in
     * PHP scales with how long the retention window is rather than with how
     * many devices they own.
     *
     * The ORDER BY is not decoration — Postgres defines `DISTINCT ON` to keep
     * the first row of each group *as ordered*, so dropping it would return an
     * arbitrary delivery per device rather than the latest one.
     *
     * @return array<string, LastDelivery> deviceClientId => its last delivery
     */
    public function lastDeliveryPerDevice(int $userId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT DISTINCT ON (device_client_id)
                       device_client_id, outcome, payload_type, created_at
                  FROM push_delivery
                 WHERE usr_id = :userId
                 ORDER BY device_client_id, created_at DESC, id DESC
                SQL,
            ['userId' => $userId],
        );

        $byDevice = [];

        foreach ($rows as $row) {
            $outcome = PushDeliveryOutcome::tryFrom((string) $row['outcome']);

            // A value the enum no longer knows is a row written by an older
            // version of this application, and the honest answer is to omit it
            // rather than to invent an outcome for it — the device then reads
            // as "nothing sent yet", which is at least not a false claim.
            if (null === $outcome) {
                continue;
            }

            $deviceClientId = (string) $row['device_client_id'];

            $byDevice[$deviceClientId] = new LastDelivery(
                $deviceClientId,
                $outcome,
                new DateTimeImmutable((string) $row['created_at']),
                null === $row['payload_type'] ? null : (string) $row['payload_type'],
            );
        }

        return $byDevice;
    }

    /** Bulk DELETE for the `<` bound, so retention never loads what it drops. */
    public function pruneOlderThan(DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('d')
            ->delete()
            ->where('d.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }

    /**
     * The WHERE both search() and countSearch() apply, in one place so the
     * table and the number above it cannot describe different sets.
     */
    private function filtered(
        ?int                 $userId,
        ?PushTransport       $transport,
        ?PushDeliveryOutcome $outcome,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('d');

        if (null !== $userId) {
            $qb->andWhere('d.usr = :userId')->setParameter('userId', $userId);
        }

        if (null !== $transport) {
            $qb->andWhere('d.transport = :transport')->setParameter('transport', $transport);
        }

        if (null !== $outcome) {
            $qb->andWhere('d.outcome = :outcome')->setParameter('outcome', $outcome);
        }

        return $qb;
    }
}
