<?php

declare(strict_types=1);

namespace App\Repository\Monitoring;

use App\Entity\Monitoring\ProcessHeartbeat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProcessHeartbeat>
 *
 * The write side here is deliberately raw DBAL rather than the ORM, and that is
 * a property of what a heartbeat is rather than an oversight. A beat is emitted
 * from inside a long-lived worker in the middle of doing something else;
 * routing it through the EntityManager would enlist it in whatever unit of work
 * happens to be open, so a beat could be lost to an unrelated rollback — or
 * drag an unrelated half-built entity into the flush it triggers. None of these
 * statements needs an identity map, and none of them may have one.
 */
class ProcessHeartbeatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessHeartbeat::class);
    }

    /**
     * @return list<ProcessHeartbeat>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['type' => 'ASC', 'key' => 'ASC']);
    }

    /**
     * Record that a process is alive.
     *
     * ON CONFLICT rather than select-then-insert-or-update: two instances of
     * the same worker can beat at the same instant, and the upsert is the only
     * form that cannot lose that race. Doctrine's API has no equivalent.
     *
     * @param array<string,mixed>|null $meta
     */
    public function upsertBeat(string $type, string $key, ?int $pid, ?array $meta): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO process_heartbeat (type, beat_key, pid, last_beat_at, meta)
             VALUES (:type, :key, :pid, NOW(), :meta)
             ON CONFLICT (type, beat_key) DO UPDATE
             SET pid = EXCLUDED.pid, last_beat_at = EXCLUDED.last_beat_at, meta = EXCLUDED.meta',
            [
                'type' => $type,
                'key'  => $key,
                'pid'  => $pid,
                'meta' => null === $meta ? null : json_encode($meta, JSON_PARTIAL_OUTPUT_ON_ERROR),
            ],
        );
    }

    /**
     * Drop one row, for a process shutting down cleanly. A bulk delete rather
     * than find-then-remove: there is nothing on the entity worth hydrating,
     * and the process doing this is already on its way out.
     */
    public function deleteBeat(string $type, string $key): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM process_heartbeat WHERE type = :type AND beat_key = :key',
            ['type' => $type, 'key' => $key],
        );
    }

    /**
     * Drop every row of $type whose key is not in $liveKeys — the reconciliation
     * a supervisor that knows the full live set can perform.
     *
     * NOT IN over a bound list, which Doctrine's API cannot express at all:
     * findBy() states membership, never its negation.
     *
     * @param list<string> $liveKeys
     */
    public function deleteOrphans(string $type, array $liveKeys): int
    {
        if ([] === $liveKeys) {
            return (int) $this->getEntityManager()->getConnection()->executeStatement(
                'DELETE FROM process_heartbeat WHERE type = :type',
                ['type' => $type],
            );
        }

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM process_heartbeat WHERE type = :type AND beat_key NOT IN (:keys)',
            ['type' => $type, 'keys' => $liveKeys],
            ['keys' => ArrayParameterType::STRING],
        );
    }

    /**
     * Reap rows of one type that have not beaten for $seconds.
     *
     * The cutoff is computed by the database (NOW() minus an interval) rather
     * than in PHP, so a worker whose clock has drifted cannot reap rows that
     * are perfectly current — or fail to reap ones that are not.
     */
    public function deleteStalerThan(string $type, int $seconds): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM process_heartbeat
              WHERE type = :type
                AND last_beat_at < NOW() - (:seconds * INTERVAL \'1 second\')',
            ['type' => $type, 'seconds' => $seconds],
        );
    }

    /**
     * The same sweep for types nobody declared a threshold for — a worker kind
     * that was added, or renamed, without the threshold table being updated.
     *
     * @param list<string> $knownTypes
     */
    public function deleteStalerThanForUnknownTypes(array $knownTypes, int $seconds): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM process_heartbeat
              WHERE type NOT IN (:known)
                AND last_beat_at < NOW() - (:seconds * INTERVAL \'1 second\')',
            ['known' => $knownTypes, 'seconds' => $seconds],
            ['known' => ArrayParameterType::STRING],
        );
    }

    /**
     * Bulk delete rather than hydrate-and-remove: a retention sweep runs over
     * rows nobody will ever look at, and loading them to delete them is the
     * whole cost this avoids.
     */
    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM process_heartbeat WHERE last_beat_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );
    }
}
