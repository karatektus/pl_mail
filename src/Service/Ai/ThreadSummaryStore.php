<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\StoredThreadSummary;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes the stored summaries, and nothing else.
 *
 * DBAL, NOT THE ORM, AND DELIBERATELY NO ENTITY
 * ─────────────────────────────────────────────
 * EmbeddingStore's house preference, for the same reason and without even its
 * excuse about `real[]`: nothing here ever needs an object. The writer has a
 * thread id and a string; the reader is a primary-key lookup. An entity would
 * mean a mapping, a repository, an identity-map participant and one more thing
 * to keep in step for a table nothing hydrates.
 *
 * "USABLE" IS THREE CONDITIONS, AND THE READ ENFORCES ALL THREE
 * ────────────────────────────────────────────────────────────
 * The model, the prompt fingerprint and the content hash. The first two are
 * EmbeddingStore::alreadyStored()'s argument transferred — "changing the model
 * has to make every old row invisible here" — because an administrator who
 * swaps chatModel has changed what a summary IS, and a row written by the
 * previous model must stop being SHOWN rather than sit there looking current.
 * The same administrator can now swap the PROMPT, from Admin → AI, which
 * changes what a summary is by at least as much; see
 * {@see ThreadSummariser::promptFingerprint()} for why that key is a hash of
 * the prompt actually sent rather than the integer somebody used to bump.
 *
 * The third is what makes this cache honest about mail that moved underneath
 * it. It is compared by the CALLER rather than in the SQL, and that is the
 * point: a row whose hash no longer matches is not invisible, it is STALE, and
 * stale is a state the reading pane renders — greyed, with the old text still
 * there and a button to write a new one. A summary of a thread that has gained
 * one message is still mostly true, and hiding it makes the half-minute
 * somebody already waited feel wasted. So this returns the row and the freshness
 * separately, and the pane decides what to do with them.
 *
 * NOTHING IS EVER DELETED HERE
 * ────────────────────────────
 * The primary key is the thread, so there is at most one row per thread ever,
 * and the write is an upsert for EmbeddingStore's stated reason:
 * "re-generation after a model change has to replace rather than collide."
 * A row for a model nobody uses any more is invisible, not accumulating, and
 * is replaced the moment anybody re-summarises. There is no pruner and no
 * console command — which also means no new line in CONTRIBUTING.md's command
 * table for the documentation test to find missing.
 *
 * A thread that is deleted takes its row with it by ON DELETE CASCADE. That is
 * the whole of the deletion story: per-thread data here is removed purely by
 * database cascade, there is no cleanup subscriber, and MessagePurger removing
 * an emptied thread is covered by the same constraint.
 */
final readonly class ThreadSummaryStore
{
    public function __construct(
        private Connection      $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The summary this thread carries, if the current model and prompt wrote it.
     *
     * Null covers every reason equally — never summarised, written by a model
     * this installation no longer uses, written under a prompt that has since
     * been edited or upgraded, or the database is unhappy — because the caller's
     * answer to all four is the same: offer the button.
     *
     * The hash is NOT in the WHERE clause. See the class docblock: a row that
     * no longer matches the conversation is the interesting case, not the
     * absent one, and the pane needs the old text to grey it out.
     */
    public function forThread(
        int    $threadId,
        string $model,
        string $promptHash,
        string $sourceHash,
        /**
         * Whether the transcript this thread produces RIGHT NOW is trimmed.
         *
         * Passed in rather than derived here, because the caller has already
         * built the transcript for the freshness hash and building it twice
         * would be two copies of one fact. Not read from the row either: the
         * question the card answers is whether the model saw all of THIS
         * conversation, and the conversation is what it is now.
         */
        bool   $isPartial = false,
    ): ?StoredThreadSummary {
        try {
            $row = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT summary, source_hash, created_at, full_context
                      FROM thread_summary
                     WHERE thread_id = :id
                       AND model = :model
                       AND prompt_hash = :prompt
                SQL,
                ['id' => $threadId, 'model' => $model, 'prompt' => $promptHash],
                ['id' => ParameterType::INTEGER],
            );
        } catch (Throwable $exception) {
            // Answering "no summary" costs a person one button press against a
            // feature they were going to press anyway. Answering with one we
            // could not verify would put a paragraph of assertions about
            // somebody's mail on the page with no idea whether it still
            // describes it. EmbeddingStore takes the same posture and says so.
            $this->logger->error('ThreadSummaryStore: could not read a stored summary', [
                'threadId'  => $threadId,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return null;
        }

        if (false === $row) {
            return null;
        }

        try {
            $writtenAt = new DateTimeImmutable((string) $row['created_at']);
        } catch (Throwable) {
            // A timestamp we cannot parse costs the "summarised on…" line and
            // nothing else. The summary itself is still the summary.
            $writtenAt = null;
        }

        return new StoredThreadSummary(
            (string) $row['summary'],
            // hash_equals rather than ===, because this is a comparison of two
            // digests and the codebase should not grow a timing-variable
            // comparison of digests anywhere, whether or not this particular
            // one is reachable by an attacker.
            hash_equals((string) $row['source_hash'], $sourceHash),
            $writtenAt,
            // Partial only if the conversation is trimmable AND this row was
            // not written from all of it. Both halves are needed: the thread
            // answers the first and only the row can answer the second, and a
            // full run on a thread that is still too long to trim normally
            // would otherwise keep a notice saying the model had not seen
            // everything — after a much longer wait during which it did.
            $isPartial && false === (bool) $row['full_context'],
        );
    }

    /**
     * Write one, replacing whatever was there.
     *
     * ON CONFLICT rather than a read-then-write: two tabs summarising the same
     * thread at once is not exotic — it is what happens when somebody presses
     * the button, gets bored, and presses it again in a second window — and the
     * loser of that race should overwrite, not raise a constraint violation
     * inside a response that has already been half sent.
     *
     * @return bool whether anything was stored
     */
    public function save(int $threadId, string $summary, string $sourceHash, string $model, string $promptHash, bool $fullContext = false): bool
    {
        try {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO thread_summary (thread_id, summary, source_hash, model, prompt_hash, full_context, created_at)
                    VALUES (:id, :summary, :hash, :model, :prompt, :full, :now)
                    ON CONFLICT (thread_id) DO UPDATE
                        SET summary      = EXCLUDED.summary,
                            source_hash  = EXCLUDED.source_hash,
                            model        = EXCLUDED.model,
                            prompt_hash  = EXCLUDED.prompt_hash,
                            full_context = EXCLUDED.full_context,
                            created_at   = EXCLUDED.created_at
                SQL,
                [
                    'id'      => $threadId,
                    'summary' => $summary,
                    'hash'    => $sourceHash,
                    'model'   => $model,
                    'prompt'  => $promptHash,
                    'full'    => $fullContext,
                    'now'     => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
                ['id' => ParameterType::INTEGER, 'full' => ParameterType::BOOLEAN],
            );

            return true;
        } catch (Throwable $exception) {
            // Logged and swallowed. This runs after the summary has already
            // been streamed to the reader, so throwing would turn a working
            // answer they can read into a 500 on a response that is already
            // half on the wire. What is lost is the CACHING, not the summary.
            $this->logger->error('ThreadSummaryStore: could not store a summary', [
                'threadId'  => $threadId,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return false;
        }
    }
}
