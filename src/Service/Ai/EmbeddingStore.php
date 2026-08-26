<?php

declare(strict_types=1);

namespace App\Service\Ai;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes the vectors, and nothing else.
 *
 * DBAL, NOT THE ORM, AND DELIBERATELY NO ENTITY
 * ─────────────────────────────────────────────
 * `real[]` is not a type Doctrine can name. Teaching it one would mean a
 * `mapping_types` entry so the comparator sees the same type on both sides and
 * stops asking to rewrite the column on every diff — the workaround this
 * codebase already carries for `tsrange`, and one more thing to keep in step
 * for a table nothing hydrates. Nothing here ever needs an object: the writer
 * has an id and an array, and the reader is a JOIN inside somebody else's SQL.
 *
 * NORMALISED HERE, ONCE
 * ─────────────────────
 * Every vector is scaled to unit length before it is stored, which is what lets
 * plmail_embed_distance() be a dot product with no square root per row. The
 * arithmetic dominates I/O about six to one in that function, so this is most
 * of the cost of a search, moved to write time where it happens once.
 *
 * A zero-length vector cannot be normalised and is refused rather than stored
 * as NaN — a single NaN in the column would poison every ORDER BY it touched.
 */
final readonly class EmbeddingStore
{
    public function __construct(
        private Connection      $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<float> $vector
     *
     * @return bool whether anything was stored
     */
    public function store(int $messageId, array $vector, string $model): bool
    {
        $unit = self::normalise($vector);

        if (null === $unit) {
            $this->logger->info('EmbeddingStore: refused a vector that cannot be normalised', [
                'messageId' => $messageId,
                'model'     => $model,
            ]);

            return false;
        }

        try {
            // Upsert: re-embedding after a model change has to replace rather
            // than collide, and the primary key is the message.
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
                    'embedding'  => self::toPostgresArray($unit),
                    'dimensions' => count($unit),
                    'model'      => $model,
                    'now'        => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );

            return true;
        } catch (Throwable $exception) {
            $this->logger->error('EmbeddingStore: could not store an embedding', [
                'messageId' => $messageId,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return false;
        }
    }

    /**
     * Which of these messages already have a usable vector.
     *
     * "Usable" includes the model: a mailbox embedded by one model and searched
     * with another returns nonsense rather than an error, so changing the model
     * has to make every old row invisible here — which is what makes a backfill
     * resume correctly instead of believing it has already finished.
     *
     * @param list<int> $messageIds
     *
     * @return list<int>
     */
    public function alreadyStored(array $messageIds, string $model): array
    {
        if ([] === $messageIds) {
            return [];
        }

        try {
            /** @var list<int> $ids */
            $ids = $this->connection->fetchFirstColumn(
                'SELECT message_id FROM message_embedding WHERE message_id IN (:ids) AND model = :model',
                ['ids' => $messageIds, 'model' => $model],
                ['ids' => ArrayParameterType::INTEGER],
            );

            return array_map(intval(...), $ids);
        } catch (Throwable $exception) {
            $this->logger->error('EmbeddingStore: could not read stored embeddings', [
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            // Answering "none" makes a backfill redo work. Answering "all"
            // would make it skip work it never did, and the second is the one
            // that leaves a mailbox permanently half-searchable.
            return [];
        }
    }

    /** How many vectors exist for a model — what a progress display counts. */
    public function countFor(string $model): int
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM message_embedding WHERE model = :model',
                ['model' => $model],
            );
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Scale to unit length, or null if that is impossible.
     *
     * @param list<float> $vector
     *
     * @return list<float>|null
     */
    private static function normalise(array $vector): ?array
    {
        if ([] === $vector) {
            return null;
        }

        $sum = 0.0;

        foreach ($vector as $component) {
            if (false === is_finite($component)) {
                // One NaN or INF in the column poisons every ORDER BY that ever
                // touches it, and it would be attributed to the search rather
                // than to the model that produced it.
                return null;
            }

            $sum += $component * $component;
        }

        $length = sqrt($sum);

        if (0.0 === $length) {
            return null;
        }

        return array_map(static fn (float $c): float => $c / $length, $vector);
    }

    /**
     * PostgreSQL array literal.
     *
     * Built by hand because DBAL has no `real[]` parameter type, and with
     * enough precision that normalisation survives the round trip — float4
     * keeps about seven significant digits, and rounding the text shorter would
     * quietly denormalise every vector on the way in.
     *
     * @param list<float> $vector
     */
    private static function toPostgresArray(array $vector): string
    {
        return '{' . implode(',', array_map(
            static fn (float $c): string => rtrim(rtrim(sprintf('%.9g', $c), '0'), '.') ?: '0',
            $vector,
        )) . '}';
    }
}
