<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Repository\Ai\MessageEmbedding\MessageEmbeddingRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes the vectors, and nothing else.
 *
 * DBAL, NOT THE ORM, AND DELIBERATELY NO ENTITY
 * ─────────────────────────────────────────────
 * `real[]` is not a type Doctrine can name, and the table is left out of schema
 * diffs by `schema_filter` in doctrine.yaml. Nothing here ever needs an object:
 * the writer has an id and an array, and the reader is a JOIN inside somebody
 * else's SQL.
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
        private MessageEmbeddingRepository $embeddings,
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
            $this->embeddings->upsert($messageId, self::toPostgresArray($unit), count($unit), $model, new DateTimeImmutable());

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
            return $this->embeddings->storedIds($messageIds, $model);
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

    /**
     * How much of ONE mailbox is embedded, and how much of it could be.
     *
     * countFor() counts the whole installation, which is the right number for
     * an admin panel and the wrong one for a person: told "4,120 of 48,900"
     * they will read it as their mail, and on a multi-user install it is not.
     *
     * Scoped by the model too, and that is not decoration. Vectors from
     * different embedding models are not comparable — different dimensionality,
     * different space — so after somebody changes the search model in settings
     * every existing vector is dead weight. Counting them would report a
     * mailbox as fully searchable at the exact moment none of it is.
     *
     * @return array{embedded: int, eligible: int}
     */
    public function coverageFor(int $userId, string $model): array
    {
        $detail = $this->coverageDetailFor($userId, $model);

        return ['embedded' => $detail['embedded'], 'eligible' => $detail['eligible']];
    }

    /**
     * The same count, plus the vectors that belong to some other model.
     *
     * WHY THE THIRD NUMBER
     * ────────────────────
     * "Nothing is embedded yet" and "everything is embedded, by a model this
     * installation no longer searches with" are the same 0 to coverageFor(),
     * and they are opposite situations for the person searching. The first is a
     * backfill that has not got there; wait. The second is a setting somebody
     * changed, and waiting will not fix it — the whole mailbox has to be
     * indexed again before meaning search does anything at all.
     *
     * ONE STATEMENT, because it is a scan of the mailbox: message joined up to
     * account is the only way to answer "of MY mail, how much", and asking it
     * twice to get two numbers would double the cost of the notice for a search
     * that has already been paid for. The caller is expected to hold the answer
     * for a while — see SemanticCoverage — because this is not a number that
     * changes between one search and the next.
     *
     * The width is part of "matching" when it is known. Two models can share a
     * name across an upgrade and answer at a different width, and the shipped
     * distance function compares whatever overlaps rather than refusing, so a
     * silently wrong ranking is exactly the failure this has to make visible.
     *
     * @return array{embedded: int, eligible: int, stale: int}
     */
    public function coverageDetailFor(int $userId, string $model, ?int $dimensions = null): array
    {
        try {
            $row = $this->embeddings->coverageForUser($userId, $model, $dimensions);
        } catch (Throwable) {
            return ['embedded' => 0, 'eligible' => 0, 'stale' => 0];
        }

        return [
            'embedded' => $row['embedded'],
            'eligible' => $row['eligible'],
            'stale'    => $row['stale'],
        ];
    }

    /**
     * The same two numbers for the WHOLE installation.
     *
     * What the admin panel counts, and it must be counted the way the walk
     * walks or the percentage never reaches a hundred: BackfillEmbeddingsHandler
     * takes its ids from MessageBackfillRepository::idsForUserAfter(), which joins
     * message to account directly. Counting through message_thread instead —
     * the join coverageFor() uses, because a person's own mailbox is a thread
     * list — answers a slightly different set, and a progress bar that stops at
     * 98% forever is read as a stuck backfill.
     *
     * Two statements rather than one LEFT JOIN over every message: both are
     * counts an index can answer, and the join would be the most expensive
     * thing on a page that polls.
     *
     * @return array{embedded: int, eligible: int}
     */
    public function coverage(string $model): array
    {
        try {
            $eligible = $this->embeddings->countEligible();
        } catch (Throwable) {
            $eligible = 0;
        }

        return ['embedded' => $this->countFor($model), 'eligible' => $eligible];
    }

    /**
     * How many vectors this model has written since a moment.
     *
     * The rate the panel shows, and the reason it is a query rather than a
     * counter: a counter lives in one worker's memory and a backfill outlives
     * several of them, so it would reset to zero every time a worker was
     * recycled and report a stalled pass. The rows carry their own timestamps,
     * so the answer survives anything.
     */
    public function storedSince(string $model, DateTimeImmutable $since): int
    {
        try {
            return $this->embeddings->countStoredSince($model, $since);
        } catch (Throwable) {
            return 0;
        }
    }

    /** How many vectors exist for a model — what a progress display counts. */
    public function countFor(string $model): int
    {
        try {
            return $this->embeddings->countForModel($model);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * A vector as a PostgreSQL literal, normalised, or null if it cannot be.
     *
     * Public and static because the SEARCH needs exactly this too: the stored
     * vectors are unit length, so the query vector has to be as well or the dot
     * product the distance function computes is not a cosine and every ranking
     * is quietly wrong. Two implementations of that would drift, and the
     * failure mode of drift here is "search ranks badly", which nothing
     * reports.
     *
     * @param list<float> $vector
     */
    public static function unitLiteral(array $vector): ?string
    {
        $unit = self::normalise($vector);

        return null === $unit ? null : self::toPostgresArray($unit);
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
