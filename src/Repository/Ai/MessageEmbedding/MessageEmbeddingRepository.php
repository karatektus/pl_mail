<?php

declare(strict_types=1);

namespace App\Repository\Ai\MessageEmbedding;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * message_embedding: one vector per message, with the model and width that
 * made it.
 *
 * Raw DBAL because the table has no entity: a vector is a real[] Doctrine has
 * no type for, and nothing reads one row as an object. EmbeddingStore decides
 * what is stored and what a failure means; this only says it in SQL.
 */
final readonly class MessageEmbeddingRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Store a message's vector, replacing whatever it had.
     *
     * @param string $literal the unit vector as a Postgres array literal
     */
    public function upsert(int $messageId, string $literal, int $dimensions, string $model, DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO message_embedding (message_id, embedding, dimensions, model, created_at)
                VALUES (:id, :embedding, :dimensions, :model, :now)
                ON CONFLICT (message_id) DO UPDATE
                    SET embedding  = EXCLUDED.embedding,
                        dimensions = EXCLUDED.dimensions,
                        model      = EXCLUDED.model,
                        created_at = EXCLUDED.created_at
            SQL,
            [
                'id'         => $messageId,
                'embedding'  => $literal,
                'dimensions' => $dimensions,
                'model'      => $model,
                'now'        => $now->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Which of these messages already have a vector from this model.
     *
     * @param non-empty-list<int> $messageIds
     *
     * @return list<int>
     */
    public function storedIds(array $messageIds, string $model): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT message_id FROM message_embedding WHERE message_id IN (:ids) AND model = :model',
            ['ids' => $messageIds, 'model' => $model],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_map(intval(...), $ids);
    }

    /**
     * One user's mail against this model's vectors: how many messages there
     * are, how many have a vector from it (of this width, when one is given),
     * and how many have a vector from something else.
     *
     * One statement whatever the caller asked, rather than two SQL strings to
     * keep in step: a null width COALESCEs into "whatever this row has", which
     * is the same predicate with the test switched off. The CAST is not
     * decoration — a bare bound NULL has no type Postgres can infer inside
     * COALESCE, and it answers `could not determine data type`.
     *
     * @return array{eligible: int, embedded: int, stale: int}
     */
    public function coverageForUser(int $userId, string $model, ?int $dimensions): array
    {
        $matches = 'e.model = :model AND e.dimensions = COALESCE(CAST(:dimensions AS int), e.dimensions)';

        $row = $this->connection->fetchAssociative(
            <<<SQL
                SELECT
                    COUNT(*)                                            AS eligible,
                    COUNT(e.message_id) FILTER (WHERE {$matches})       AS embedded,
                    COUNT(e.message_id) FILTER (WHERE NOT ({$matches})) AS stale
                  FROM message m
                  JOIN message_thread t ON t.id = m.thread_id
                  JOIN account a ON a.id = t.account_id
                  LEFT JOIN message_embedding e ON e.message_id = m.id
                 WHERE a.usr_id = :userId
            SQL,
            [
                'userId'     => $userId,
                'model'      => $model,
                'dimensions' => $dimensions,
            ],
            [
                'userId'     => ParameterType::INTEGER,
                'dimensions' => null === $dimensions ? ParameterType::NULL : ParameterType::INTEGER,
            ],
        );

        if (false === $row) {
            return ['eligible' => 0, 'embedded' => 0, 'stale' => 0];
        }

        return [
            'eligible' => (int) $row['eligible'],
            'embedded' => (int) $row['embedded'],
            'stale'    => (int) $row['stale'],
        ];
    }

    /** Every message on the installation that belongs to an account. */
    public function countEligible(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM message m JOIN account a ON a.id = m.account_id',
        );
    }

    /** Vectors this model made since $since. */
    public function countStoredSince(string $model, DateTimeImmutable $since): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM message_embedding WHERE model = :model AND created_at >= :since',
            ['model' => $model, 'since' => $since->format('Y-m-d H:i:s')],
        );
    }

    /** Vectors this model made. */
    public function countForModel(string $model): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM message_embedding WHERE model = :model',
            ['model' => $model],
        );
    }

    /** A message's vector, gone. */
    public function deleteForMessage(int $messageId): void
    {
        $this->connection->executeStatement('DELETE FROM message_embedding WHERE message_id = ?', [$messageId]);
    }

    /** The vectors of an account's mail whose plaintext came from a browser. */
    public function deleteForPlaintextSource(int $accountId, string $plaintextSource): void
    {
        $this->connection->executeStatement(
            'DELETE FROM message_embedding WHERE message_id IN (SELECT id FROM message WHERE account_id = ? AND plaintext_source = ?)',
            [$accountId, $plaintextSource],
        );
    }
}
