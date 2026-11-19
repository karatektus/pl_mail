<?php

declare(strict_types=1);

namespace App\Repository\Mail\SenderPicture;

use App\Domain\Enum\Mail\SenderPictureKind;
use App\Domain\Enum\Mail\SenderPictureStatus;
use App\Entity\Mail\SenderPicture;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The sender picture lookups (#639): claiming them for the background job,
 * finding the ones to show, and forgetting senders who stopped writing.
 *
 * @extends ServiceEntityRepository<SenderPicture>
 */
class SenderPictureRepository extends ServiceEntityRepository
{
    /**
     * How long a claimed row may stay Pending before it is taken to be
     * abandoned — a worker killed mid-fetch never writes an answer. Far past
     * the job's own budget (three requests of ten seconds each, at worst).
     */
    private const int STUCK_MINUTES = 60;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SenderPicture::class);
    }

    /**
     * Records that mail from these subjects just arrived, and claims the ones
     * whose lookup is due. Returns the claimed subjects: the caller queues a
     * job for each of those and for nothing else.
     *
     * **Statements that decide under the row lock, because a read-then-write
     * loses the race this exists to win.** Two ingest batches carrying the
     * same newsletter run at once on a multi-account install; both would read
     * "no row" or "stale row", and both would queue a lookup.
     *
     *  1. New subjects are inserted as Pending with ON CONFLICT DO NOTHING. A
     *     concurrent insert of the same subject waits for the first to commit
     *     and then does nothing, so only one of them gets the row back.
     *  2. Existing rows whose answer is old enough are moved to Pending by an
     *     UPDATE whose WHERE holds the due condition. Under READ COMMITTED a
     *     second UPDATE blocked on the same row re-evaluates that WHERE against
     *     the row the first one wrote — now Pending, claimed a moment ago, not
     *     due — and leaves it alone.
     *  3. Everything else only has its last_seen_at moved, at most hourly, so
     *     a busy sender is not a write per batch.
     *
     * One RETURNING flag instead of this was the first version, comparing
     * `claimed_at` with the statement's own timestamp. The column holds whole
     * seconds, so a concurrent claim in the same second matched too, and both
     * batches queued the job.
     *
     * Raw DBAL for ON CONFLICT and RETURNING, which the ORM cannot express.
     *
     * @param list<string> $subjects
     *
     * @return list<string>
     */
    public function claim(SenderPictureKind $kind, array $subjects): array
    {
        $subjects = array_values(array_unique($subjects));

        if ([] === $subjects) {
            return [];
        }

        $connection = $this->getEntityManager()->getConnection();
        $parameters = [
            'kind'     => $kind->value,
            'pending'  => SenderPictureStatus::Pending->value,
            'subjects' => $subjects,
        ];
        $types = ['subjects' => ArrayParameterType::STRING];

        /** @var list<string> $inserted */
        $inserted = $connection->fetchFirstColumn(
            <<<'SQL'
                INSERT INTO sender_picture (kind, subject, status, claimed_at, last_seen_at, created_at, updated_at)
                SELECT :kind, subject, :pending, LOCALTIMESTAMP(0), LOCALTIMESTAMP(0), LOCALTIMESTAMP(0), LOCALTIMESTAMP(0)
                  FROM unnest(ARRAY[:subjects]::text[]) AS subject
                ON CONFLICT (kind, subject) DO NOTHING
                RETURNING subject
                SQL,
            $parameters,
            $types,
        );

        $due = self::dueCondition('sender_picture');

        /** @var list<string> $reclaimed */
        $reclaimed = $connection->fetchFirstColumn(
            <<<SQL
                UPDATE sender_picture
                   SET status = :pending, claimed_at = LOCALTIMESTAMP(0),
                       last_seen_at = LOCALTIMESTAMP(0), updated_at = LOCALTIMESTAMP(0)
                 WHERE kind = :kind
                   AND subject IN (:subjects)
                   AND {$due}
                RETURNING subject
                SQL,
            $parameters,
            $types,
        );

        $connection->executeStatement(
            <<<'SQL'
                UPDATE sender_picture
                   SET last_seen_at = LOCALTIMESTAMP(0)
                 WHERE kind = :kind
                   AND subject IN (:subjects)
                   AND last_seen_at < LOCALTIMESTAMP(0) - INTERVAL '1 hour'
                SQL,
            ['kind' => $kind->value, 'subjects' => $subjects],
            $types,
        );

        return array_values(array_unique([...$inserted, ...$reclaimed]));
    }

    /**
     * Claims up to $limit rows whose answer has gone stale, among senders seen
     * since $seenSince — the scheduled refresh. Returns them as kind/subject
     * pairs for the caller to queue.
     *
     * SKIP LOCKED so two refreshes running at once (a manual run during the
     * scheduled one) take different rows instead of queueing the same ones.
     *
     * @return list<array{kind: SenderPictureKind, subject: string}>
     */
    public function claimDueForRefresh(\DateTimeImmutable $seenSince, int $limit): array
    {
        $due = self::dueCondition('candidate');

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<SQL
                UPDATE sender_picture
                   SET status = :pending, claimed_at = LOCALTIMESTAMP(0), updated_at = LOCALTIMESTAMP(0)
                 WHERE id IN (
                       SELECT candidate.id
                         FROM sender_picture candidate
                        WHERE candidate.last_seen_at >= :seenSince
                          AND {$due}
                        ORDER BY candidate.last_seen_at DESC
                        LIMIT :limit
                          FOR UPDATE SKIP LOCKED
                 )
                RETURNING kind, subject
                SQL,
            [
                'pending'   => SenderPictureStatus::Pending->value,
                'seenSince' => $seenSince->format('Y-m-d H:i:s'),
                'limit'     => $limit,
            ],
        );

        $claimed = [];

        foreach ($rows as $row) {
            $kind = SenderPictureKind::tryFrom((string) $row['kind']);

            if (null !== $kind) {
                $claimed[] = ['kind' => $kind, 'subject' => (string) $row['subject']];
            }
        }

        return $claimed;
    }

    public function findOneFor(SenderPictureKind $kind, string $subject): ?SenderPicture
    {
        return $this->findOneBy(['kind' => $kind, 'subject' => $subject]);
    }

    /**
     * The stored pictures for these subjects, per kind — one query for a whole
     * list page. Only Found rows; the caller has nothing to do with the rest.
     *
     * @param array<string, list<string>> $subjectsByKind kind value => subjects
     *
     * @return array<string, array<string, array{id: int, file: string}>> kind value => subject => picture
     */
    public function findFound(array $subjectsByKind): array
    {
        $conditions = [];
        $parameters = ['found' => SenderPictureStatus::Found->value];
        $types      = [];

        foreach ($subjectsByKind as $kind => $subjects) {
            if ([] === $subjects) {
                continue;
            }

            $index = count($conditions);

            $conditions[]                       = sprintf('(kind = :kind%1$d AND subject IN (:subjects%1$d))', $index);
            $parameters['kind' . $index]        = $kind;
            $parameters['subjects' . $index]    = array_values(array_unique($subjects));
            $types['subjects' . $index]         = ArrayParameterType::STRING;
        }

        if ([] === $conditions) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            sprintf(
                'SELECT id, kind, subject, file FROM sender_picture WHERE status = :found AND file IS NOT NULL AND (%s)',
                implode(' OR ', $conditions),
            ),
            $parameters,
            $types,
        );

        $found = [];

        foreach ($rows as $row) {
            $found[(string) $row['kind']][(string) $row['subject']] = [
                'id'   => (int) $row['id'],
                'file' => (string) $row['file'],
            ];
        }

        return $found;
    }

    /**
     * Forgets senders not seen since $cutoff. Their files are left for
     * referencedFiles() to tell the store which are still in use.
     */
    public function pruneNotSeenSince(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('picture')
            ->delete()
            ->where('picture.lastSeenAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }

    /**
     * Every file a row still names. Many rows share one file — fifty addresses
     * at one company, one BIMI logo — so a file is garbage only when no row
     * at all names it.
     *
     * @return list<string>
     */
    public function referencedFiles(): array
    {
        /** @var list<string> */
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT file FROM sender_picture WHERE file IS NOT NULL',
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The WHERE that says a row's answer is old enough to ask again, written
     * once and shared by the ingest claim and the refresh so the two cannot
     * disagree about what "due" means. Built from SenderPictureStatus's own
     * numbers, which are integers, so the interpolation carries no input.
     */
    private static function dueCondition(string $alias): string
    {
        $clauses = [sprintf(
            "(%1\$s.status = '%2\$s' AND (%1\$s.claimed_at IS NULL OR %1\$s.claimed_at < LOCALTIMESTAMP(0) - INTERVAL '%3\$d minutes'))",
            $alias,
            SenderPictureStatus::Pending->value,
            self::STUCK_MINUTES,
        )];

        foreach ([SenderPictureStatus::Found, SenderPictureStatus::Missing, SenderPictureStatus::Failed] as $status) {
            $clauses[] = sprintf(
                "(%1\$s.status = '%2\$s' AND (%1\$s.checked_at IS NULL OR %1\$s.checked_at < LOCALTIMESTAMP(0) - INTERVAL '%3\$d days'))",
                $alias,
                $status->value,
                $status->refreshAfterDays(),
            );
        }

        return '(' . implode(' OR ', $clauses) . ')';
    }
}
